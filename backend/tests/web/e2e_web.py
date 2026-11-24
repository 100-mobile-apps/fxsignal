#!/usr/bin/env python3
"""End-to-end test of the website, Paystack (mocked), affiliate programme and admin panel.

Needs: MariaDB with database `fxweb` loaded from schema.sql + tests/web/seed.php, the fake SMTP
server on port 2526 (tests/fake_smtp.py) and the dev server started with the Paystack mock:

  FX_CONFIG=$PWD/tests/web/config.php FX_PAYSTACK_MOCK=1 php -S 127.0.0.1:8093 dev-router.php
  python3 tests/web/e2e_web.py
"""
import email, glob, hashlib, hmac, json, os, re, subprocess, sys, time
import requests

BASE = 'http://127.0.0.1:8093'
HERE = os.path.dirname(os.path.abspath(__file__))
MAILBOX = os.path.join(HERE, '..', 'mailbox')
SECRET = 'sk_test_fake_secret_for_tests'
RUN = str(int(time.time()))[-6:]
passed = failed = 0


def check(name, ok, info=''):
    global passed, failed
    if ok:
        passed += 1
        print('  ok  ', name)
    else:
        failed += 1
        print('  FAIL', name, info)


def sql(q):
    out = subprocess.run(['mysql', '-uroot', 'fxweb', '-N', '-B', '-e', q], capture_output=True, text=True)
    if out.returncode:
        raise RuntimeError(out.stderr)
    return [line.split('\t') for line in out.stdout.strip().splitlines() if line]


def csrf(s, path):
    r = s.get(BASE + path)
    m = re.search(r'name="csrf" value="([^"]+)"', r.text)
    assert m, f'no csrf on {path} ({r.status_code})'
    return m.group(1)


def post(s, path, data, page=None):
    data = dict(data, csrf=csrf(s, page or path))
    return s.post(BASE + path, data=data, allow_redirects=True)


def latest_code(to):
    deadline = time.time() + 10
    while time.time() < deadline:
        for f in sorted(glob.glob(os.path.join(MAILBOX, '*.eml')), key=os.path.getmtime, reverse=True):
            raw = open(f, 'rb').read()
            msg = email.message_from_bytes(raw)
            if to.lower() not in (msg.get('To') or '').lower():
                continue
            body = ''
            for part in msg.walk():
                if part.get_content_type() == 'text/plain':
                    body += part.get_payload(decode=True).decode('utf-8', 'replace')
            m = re.search(r'\b(\d{3}) ?(\d{3})\b', body)
            if m:
                return m.group(1) + m.group(2)
        time.sleep(0.5)
    return None


def register(s, name, mail, ref=None):
    data = {'name': name, 'email': mail, 'password': 'Passw0rd!x', 'terms': '1'}
    if ref is not None:
        data['ref'] = ref
    r = post(s, '/register', data)
    code = latest_code(mail)
    r2 = post(s, '/verify', {'code': code or '000000'})
    return r2


def flush_mail():
    # cron sends queued emails; run it once so verification/payout emails land in the mailbox
    subprocess.run(['php', '-r', 'require "api/src/bootstrap.php"; Mailer::processQueue();'],
                   cwd=os.path.join(HERE, '..', '..'), env=dict(os.environ, FX_CONFIG=os.path.join(HERE, 'config.php')),
                   capture_output=True)


def webhook(event, data):
    body = json.dumps({'event': event, 'data': data}).encode()
    sig = hmac.new(SECRET.encode(), body, hashlib.sha512).hexdigest()
    return requests.post(BASE + '/api/billing/paystack', data=body, headers={'X-Paystack-Signature': sig, 'Content-Type': 'application/json'})


print('Public pages')
for path in ['/', '/results', '/how-it-works', '/pricing', '/affiliates', '/tools', '/terms', '/privacy', '/risk', '/affiliate-terms', '/sitemap.xml', '/robots.txt', '/login', '/register', '/forgot']:
    r = requests.get(BASE + path)
    check(f'GET {path} 200', r.status_code == 200, r.status_code)
check('unknown page 404', requests.get(BASE + '/nope').status_code == 404)
check('member area needs login', requests.get(BASE + '/app', allow_redirects=False).status_code in (302, 303))

print('Affiliate signs up and joins')
A = requests.Session()
amail = f'aff{RUN}@example.com'
register(A, 'Tunde Affiliate', amail)
r = A.get(BASE + '/app')
check('affiliate verified and in member area', r.status_code == 200 and 'Signals' in r.text, r.url)
r = post(A, '/app/affiliate', {'do': 'join', 'code': f'TUNDE{RUN[-3:]}'})
code = f'TUNDE{RUN[-3:]}'
check('joined with chosen code', code in r.text, r.text[:200])
r = post(A, '/app/affiliate', {'do': 'broker', 'broker_name': 'Exness', 'broker_url': 'https://one.exness.link/a/tunde'})
check('broker link pending review', 'sent for review' in r.text)
r = post(A, '/app/affiliate', {'do': 'payout_details', 'method': 'bank', 'details': 'GTBank 0123456789 Tunde Affiliate'})
check('payout details saved', 'Payout details saved' in r.text)
aid = int(sql(f"SELECT id FROM users WHERE email = '{amail}'")[0][0])

print('Referral link, sign-up and Premium via Paystack')
B = requests.Session()
r = B.get(BASE + f'/r/{code}', allow_redirects=False)
check('/r/CODE redirects and sets cookie', r.status_code in (302, 303) and 'fx_ref' in r.headers.get('Set-Cookie', ''))
B.get(BASE + f'/r/{code}')
bmail = f'buyer{RUN}@example.com'
register(B, 'Bola Buyer', bmail)
row = sql(f"SELECT id, referred_by FROM users WHERE email = '{bmail}'")[0]
bid = int(row[0])
check('buyer linked to affiliate', row[1] == str(aid), row)
check('visit counted', int(sql(f"SELECT COUNT(*) FROM referral_clicks WHERE affiliate_id = {aid} AND kind = 'visit'")[0][0]) >= 1)

r = B.get(BASE + '/app')
check('free user sees locked Premium signal', 'Premium' in r.text and ('lock' in r.text.lower()))
# mock checkout redirects to https://checkout.paystack.com/mock-REF (requests won't follow off-site in this sandbox)
r = B.post(BASE + '/app/premium', data={'plan': 'monthly', 'csrf': csrf(B, '/app/premium')}, allow_redirects=False)
loc = r.headers.get('Location', '')
check('checkout redirects to Paystack', loc.startswith('https://checkout.paystack.com/'), loc)
ref = loc.rsplit('mock-', 1)[-1]
r = B.get(BASE + f'/app/premium/return?reference={ref}')
check('unpaid return does not unlock', 'Premium is active' not in r.text)
requests.get(BASE + f'/__mock/paystack/pay?reference={ref}')
r = B.get(BASE + f'/app/premium/return?reference={ref}')
check('paid return unlocks Premium', 'Premium is active' in B.get(BASE + '/app/premium').text, r.url)
w = webhook('charge.success', {'reference': ref})
check('webhook for same payment accepted', w.status_code == 200, w.text)
check('payment recorded once', sql(f"SELECT COUNT(*) FROM payments WHERE user_id = {bid}")[0][0] == '1')
comm = sql(f"SELECT amount_usd, status, rate FROM commissions WHERE affiliate_id = {aid}")
check('30% commission on hold', len(comm) == 1 and comm[0][1] == 'pending' and abs(float(comm[0][0]) - round(2500 / 1600 * 0.3, 2)) < 0.02, comm)
bad = requests.post(BASE + '/api/billing/paystack', data=b'{"event":"charge.success","data":{"reference":"x"}}', headers={'X-Paystack-Signature': 'bad'})
check('webhook with bad signature rejected', bad.status_code == 401, bad.status_code)

print('Renewal by webhook (recurring commission)')
ref2 = ref + 'R2'
requests.get(BASE + f'/__mock/paystack/pay?reference={ref}&newref={ref2}&offset=2592000')
w = webhook('charge.success', {'reference': ref2})
check('renewal recorded', sql(f"SELECT COUNT(*) FROM payments WHERE user_id = {bid} AND status = 'paid'")[0][0] == '2')
check('second commission created', sql(f"SELECT COUNT(*) FROM commissions WHERE affiliate_id = {aid}")[0][0] == '2')

print('Test-mode payments pay no commission')
C = requests.Session()
C.get(BASE + f'/r/{code}')
cmail = f'tester{RUN}@example.com'
register(C, 'Test Buyer', cmail)
r = C.post(BASE + '/app/premium', data={'plan': 'yearly', 'csrf': csrf(C, '/app/premium')}, allow_redirects=False)
tref = r.headers.get('Location', '').rsplit('mock-', 1)[-1]
requests.get(BASE + f'/__mock/paystack/pay?reference={tref}&domain=test')
C.get(BASE + f'/app/premium/return?reference={tref}')
cid = sql(f"SELECT id FROM users WHERE email = '{cmail}'")[0][0]
check('test payment unlocks Premium', 'Premium is active' in C.get(BASE + '/app/premium').text)
check('test payment flagged, no commission', sql(f"SELECT p.test, (SELECT COUNT(*) FROM commissions WHERE payment_id = p.id) FROM payments p WHERE user_id = {cid}") == [['1', '0']])

print('App API: register with referral code')
r = requests.post(BASE + '/api/auth/register', json={'name': 'App User', 'email': f'app{RUN}@example.com', 'password': 'Passw0rd!x', 'accepted_terms': True, 'ref': code})
check('app register 201', r.status_code == 201, r.text[:200])
check('app user referred', sql(f"SELECT referred_by FROM users WHERE email = 'app{RUN}@example.com'")[0][0] == str(aid))
r = requests.post(BASE + '/api/auth/register', json={'name': 'Self Ref', 'email': amail, 'password': 'Passw0rd!x', 'accepted_terms': True, 'ref': code})
check('duplicate email rejected', r.status_code >= 400)

print('Hold period, payout request')
r = post(A, '/app/affiliate', {'do': 'payout'})
check('payout refused while on hold', 'minimum payout' in r.text.lower() or 'available' in r.text.lower())
sql(f"UPDATE commissions SET available_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE affiliate_id = {aid}")
sql("REPLACE INTO settings (k, v, updated_at) VALUES ('affiliate', '{\"enabled\":true,\"rate\":30,\"hold_days\":14,\"min_payout\":0.5,\"cookie_days\":60,\"auto_approve\":true}', UTC_TIMESTAMP())")
r = post(A, '/app/affiliate', {'do': 'payout'})
check('payout requested', 'Payout requested' in r.text, re.findall(r'class="flash[^"]*">([^<]+)', r.text))
po = sql(f"SELECT id, amount_usd, status FROM payouts WHERE affiliate_id = {aid}")
check('payout row covers both commissions', len(po) == 1 and abs(float(po[0][1]) - 2 * round(2500 / 1600 * 0.3, 2)) < 0.03, po)

print('Admin panel')
admin_mail = 'admin@example.com'
if not sql(f"SELECT id FROM users WHERE email = '{admin_mail}'"):
    requests.post(BASE + '/api/auth/register', json={'name': 'Owner', 'email': admin_mail, 'password': 'Passw0rd!x', 'accepted_terms': True})
D = requests.Session()
r = D.get(BASE + '/admin/')
check('admin login page', r.status_code == 200 and 'Sign in' in r.text)
r = post(D, '/admin/', {'action': 'login', 'email': admin_mail, 'password': 'wrong'})
check('wrong admin password refused', 'Wrong email or password' in r.text)
r = post(D, '/admin/', {'action': 'login', 'email': admin_mail, 'password': 'Passw0rd!x'})
check('admin signed in', 'Sign out' in r.text)
N = requests.Session()
post(N, '/admin/', {'action': 'login', 'email': bmail, 'password': 'Passw0rd!x'})
check('non-admin cannot sign in', 'Sign out' not in N.get(BASE + '/admin/').text)
for tab in ['overview', 'users', 'payments', 'affiliates', 'commissions', 'payouts', 'settings']:
    r = D.get(BASE + f'/admin/?tab={tab}')
    check(f'admin tab {tab}', r.status_code == 200 and 'Fatal' not in r.text and 'Warning' not in r.text, r.text[-300:])
check('overview shows revenue', 'Last 30 days' in D.get(BASE + '/admin/?tab=overview').text)
check('users search finds buyer', bmail in D.get(BASE + '/admin/?tab=users&q=buyer' + RUN).text)
r = post(D, '/admin/?tab=affiliates', {'action': 'broker', 'user_id': aid, 'decision': 'approve'}, page='/admin/?tab=affiliates')
check('broker link approved', sql(f"SELECT broker_status FROM affiliates WHERE user_id = {aid}")[0][0] == 'approved')
r = B.get(BASE + '/go/broker', allow_redirects=False)
check("referred user's broker button goes to affiliate's link", r.headers.get('Location', '') == 'https://one.exness.link/a/tunde', r.headers.get('Location'))
r = post(D, '/admin/?tab=payouts', {'action': 'payout', 'id': po[0][0], 'decision': 'paid', 'reference': ''}, page='/admin/?tab=payouts')
check('mark paid needs a reference', 'Enter the transfer reference' in r.text)
r = post(D, '/admin/?tab=payouts', {'action': 'payout', 'id': po[0][0], 'decision': 'paid', 'reference': 'GTB-998877'}, page='/admin/?tab=payouts')
check('payout marked paid', sql(f"SELECT status FROM payouts WHERE id = {po[0][0]}")[0][0] == 'paid')
check('commissions paid', sql(f"SELECT COUNT(*) FROM commissions WHERE affiliate_id = {aid} AND status = 'paid'")[0][0] == '2')
r = post(D, '/admin/?tab=users', {'action': 'grant', 'user_id': aid, 'days': '30'}, page='/admin/?tab=users')
check('grant Premium', 'Premium granted' in r.text)
check('grant creates no commission', sql(f"SELECT COUNT(*) FROM commissions c JOIN payments p ON p.id = c.payment_id WHERE p.source = 'manual'")[0][0] == '0')
pid = sql(f"SELECT id FROM payments WHERE user_id = {bid} ORDER BY id DESC LIMIT 1")[0][0]
r = post(D, '/admin/?tab=payments', {'action': 'refund', 'payment_id': pid, 'confirm': 'yes'}, page='/admin/?tab=payments')
check('refund marked', sql(f"SELECT status FROM payments WHERE id = {pid}")[0][0] == 'refunded')
r = post(D, '/admin/?tab=settings', {'action': 'save_web', 'currency': 'NGN', 'monthly_amount': '3000', 'yearly_amount': '24000', 'usd_rate': '1550',
                                       'aff_enabled': '1', 'aff_rate': '25', 'aff_hold_days': '10', 'aff_min_payout': '10', 'aff_cookie_days': '30',
                                       'broker_name': 'Owner Broker', 'broker_url': 'https://broker.example.com/?ib=owner',
                                       'play_monthly': '4.24', 'play_yearly': '30.59', 'play_monthly_intro': '1.28', 'play_yearly_intro': '10.2'}, page='/admin/?tab=settings')
check('web/affiliate settings saved', 'settings saved' in r.text and '"rate":25' in sql("SELECT v FROM settings WHERE k = 'affiliate'")[0][0])
check('new price on pricing page', '3,000' in requests.get(BASE + '/pricing').text)
r = post(D, '/admin/?tab=users', {'action': 'block', 'user_id': bid}, page='/admin/?tab=users')
check('suspend user', sql(f"SELECT blocked_at IS NOT NULL FROM users WHERE id = {bid}")[0][0] == '1')
check('suspended user signed out of website', B.get(BASE + '/app', allow_redirects=False).status_code in (302, 303))

print(f'\nRESULT: {passed} passed, {failed} failed')
sys.exit(1 if failed else 0)

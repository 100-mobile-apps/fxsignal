# Minimal SMTP server for tests: accepts AUTH LOGIN and saves each message to ./mailbox/N.eml
import asyncio, os, sys
BOX = os.path.join(os.path.dirname(__file__), 'mailbox'); os.makedirs(BOX, exist_ok=True)
count = len(os.listdir(BOX))
async def handle(r, w):
    global count
    def send(s): w.write((s + '\r\n').encode())
    send('220 test ESMTP'); await w.drain()
    rcpt = []; auth_step = 0
    while True:
        line = await r.readline()
        if not line: break
        cmd = line.decode().rstrip('\r\n')
        u = cmd.upper()
        if auth_step:
            auth_step += 1
            send('334 UGFzc3dvcmQ6' if auth_step == 2 else '235 ok')
            if auth_step == 3: auth_step = 0
        elif u.startswith('EHLO'): send('250-test'); send('250 AUTH LOGIN')
        elif u.startswith('AUTH LOGIN'): auth_step = 1; send('334 VXNlcm5hbWU6')
        elif u.startswith('MAIL FROM'): rcpt = []; send('250 ok')
        elif u.startswith('RCPT TO'): rcpt.append(cmd[8:].strip('<> ')); send('250 ok')
        elif u == 'DATA':
            send('354 go'); await w.drain(); data = []
            while True:
                l = await r.readline()
                if l in (b'.\r\n', b'.\n'): break
                data.append(l)
            count += 1
            with open(os.path.join(BOX, f'{count:05d}.eml'), 'wb') as f: f.write(b''.join(data))
            send('250 queued')
        elif u == 'RSET': send('250 ok')
        elif u == 'QUIT': send('221 bye'); await w.drain(); break
        else: send('250 ok')
        await w.drain()
    w.close()
async def main():
    srv = await asyncio.start_server(handle, '127.0.0.1', int(sys.argv[1]) if len(sys.argv) > 1 else 2525)
    async with srv: await srv.serve_forever()
asyncio.run(main())

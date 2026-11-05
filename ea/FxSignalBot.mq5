//+------------------------------------------------------------------+
//| FxSignalBot.mq5 - places the app's signals on YOUR MetaTrader 5  |
//| account using the two-trade method:                              |
//|   trade A -> TP1, trade B -> TP2                                 |
//|   after TP1: B's stop moves to entry                             |
//|   halfway from TP1 to TP2: B's stop moves to TP1                 |
//| Your broker login never leaves your computer. The bot only reads |
//| signals from the app server with your personal key.              |
//| Needs a HEDGING account (most retail accounts are).              |
//+------------------------------------------------------------------+
#property copyright "FX Signal"
#property version   "1.00"

#include <Trade\Trade.mqh>

input string ApiKey            = "";                                          // Your bot key from the app (More > Auto-trade bot)
input string ApiUrl            = "https://forexsignal.bulksend.top/ea/signals"; // Allow this URL in Tools > Options > Expert Advisors
input double RiskPercent       = 0;      // Risk per signal in % of balance. 0 = use the risk set in the app
input double MaxRiskPercent    = 30;     // Never risk more than this per signal, whatever the app says
input double MaxOverRiskFactor = 2.0;    // Small accounts: allow the 0.01 lot minimum if its real risk <= requested x this. Otherwise skip
input double DailyLossLimitPct = 0;      // Stop opening new signals when equity is down this % today. 0 = off
input int    MaxOpenSignals    = 6;      // Max signals open at the same time
input double MaxDriftPctOfStop = 25;     // Skip a signal if price already ran this % of the stop distance away from entry
input int    MaxSignalAgeMin   = 90;     // Skip signals older than this
input int    PollSeconds       = 30;     // How often to check the server
input long   MagicNumber       = 770031; // Identifies this bot's trades
input string SymbolSuffix      = "";     // Broker suffix, e.g. ".m" or "pro"
input string SymbolMap         = "";     // Optional renames, e.g. XAU/USD=GOLD;USD/JPY=USDJPYm
input int    SlippagePoints    = 30;
input bool   EnableTrading     = true;   // false = watch only, no orders

CTrade   trade;
datetime g_lastOk = 0;
string   g_status = "starting";
string   g_strategy = "";
double   g_appRisk = 1.0;
double   g_dayStartEquity = 0;
int      g_dayOfYear = -1;

//------------------------------------------------------------------ helpers
string Unslash(string s) { StringReplace(s, "\\/", "/"); return s; }

// value of "key": in a flat JSON object string; strings come back without quotes
string JVal(const string obj, const string key)
{
   string pat = "\"" + key + "\":";
   int p = StringFind(obj, pat);
   if(p < 0) return "";
   p += StringLen(pat);
   int n = StringLen(obj);
   while(p < n && StringGetCharacter(obj, p) == ' ') p++;
   if(p >= n) return "";
   if(StringGetCharacter(obj, p) == '"')
   {
      int e = p + 1;
      while(e < n)
      {
         ushort c = StringGetCharacter(obj, e);
         if(c == '\\') { e += 2; continue; }
         if(c == '"') break;
         e++;
      }
      return Unslash(StringSubstr(obj, p + 1, e - p - 1));
   }
   int e = p;
   while(e < n)
   {
      ushort c = StringGetCharacter(obj, e);
      if(c == ',' || c == '}' || c == ']') break;
      e++;
   }
   return StringSubstr(obj, p, e - p);
}

// all {...} objects inside the array called name
int JObjects(const string body, const string name, string &out[])
{
   ArrayResize(out, 0);
   string pat = "\"" + name + "\":[";
   int p = StringFind(body, pat);
   if(p < 0) return 0;
   p += StringLen(pat);
   int n = StringLen(body);
   while(p < n)
   {
      ushort c = StringGetCharacter(body, p);
      if(c == ']') break;
      if(c == '{')
      {
         int depth = 0, e = p;
         bool inStr = false;
         while(e < n)
         {
            ushort d = StringGetCharacter(body, e);
            if(inStr) { if(d == '\\') e++; else if(d == '"') inStr = false; }
            else if(d == '"') inStr = true;
            else if(d == '{') depth++;
            else if(d == '}') { depth--; if(depth == 0) break; }
            e++;
         }
         int k = ArraySize(out);
         ArrayResize(out, k + 1);
         out[k] = StringSubstr(body, p, e - p + 1);
         p = e + 1;
         continue;
      }
      p++;
   }
   return ArraySize(out);
}

datetime ParseIso(string s)   // 2026-11-02T15:00:00Z (UTC)
{
   StringReplace(s, "-", ".");
   StringReplace(s, "T", " ");
   StringReplace(s, "Z", "");
   return StringToTime(s);
}

string BrokerSymbol(string pair)
{
   // 1) explicit map
   string items[];
   int n = StringSplit(SymbolMap, ';', items);
   for(int i = 0; i < n; i++)
   {
      string kv[];
      if(StringSplit(items[i], '=', kv) == 2)
      {
         StringTrimLeft(kv[0]); StringTrimRight(kv[0]); StringTrimLeft(kv[1]); StringTrimRight(kv[1]);
         if(kv[0] == pair) { SymbolSelect(kv[1], true); return kv[1]; }
      }
   }
   // 2) EUR/USD -> EURUSD + suffix
   string s = pair;
   StringReplace(s, "/", "");
   string cand[3];
   cand[0] = s + SymbolSuffix;
   cand[1] = (pair == "XAU/USD") ? "GOLD" + SymbolSuffix : "";
   cand[2] = (pair == "BTC/USD") ? "BTCUSD" + SymbolSuffix : "";
   for(int i = 0; i < 3; i++)
   {
      if(cand[i] == "") continue;
      if(SymbolSelect(cand[i], true)) return cand[i];
   }
   return "";
}

bool FindPos(const long id, const string leg, ulong &ticket)
{
   string tag = "FXS" + (string)id + leg;
   for(int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong t = PositionGetTicket(i);
      if(t == 0) continue;
      if(PositionGetInteger(POSITION_MAGIC) != MagicNumber) continue;
      if(PositionGetString(POSITION_COMMENT) == tag) { ticket = t; return true; }
   }
   return false;
}

int OpenSignalCount()
{
   long seen[]; int c = 0;
   for(int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong t = PositionGetTicket(i);
      if(t == 0 || PositionGetInteger(POSITION_MAGIC) != MagicNumber) continue;
      string cm = PositionGetString(POSITION_COMMENT);
      if(StringLen(cm) < 5 || StringSubstr(cm, 0, 3) != "FXS") continue;
      long id = StringToInteger(StringSubstr(cm, 3, StringLen(cm) - 4));
      bool dup = false;
      for(int k = 0; k < c; k++) if(seen[k] == id) { dup = true; break; }
      if(!dup) { ArrayResize(seen, c + 1); seen[c] = id; c++; }
   }
   return c;
}

string GVn(const long id, const string suffix) { return "FXS_" + (string)id + suffix; }

bool DailyLimitHit()
{
   if(DailyLossLimitPct <= 0) return false;
   MqlDateTime dt; TimeToStruct(TimeCurrent(), dt);
   if(dt.day_of_year != g_dayOfYear) { g_dayOfYear = dt.day_of_year; g_dayStartEquity = AccountInfoDouble(ACCOUNT_EQUITY); }
   return AccountInfoDouble(ACCOUNT_EQUITY) < g_dayStartEquity * (1.0 - DailyLossLimitPct / 100.0);
}

// lots for ONE half position; 0 = skip
double HalfLots(const string sym, const double stopDist, const double riskPct, double &realRiskPct)
{
   double tickSize = SymbolInfoDouble(sym, SYMBOL_TRADE_TICK_SIZE);
   double tickVal  = SymbolInfoDouble(sym, SYMBOL_TRADE_TICK_VALUE_LOSS);
   if(tickVal <= 0) tickVal = SymbolInfoDouble(sym, SYMBOL_TRADE_TICK_VALUE);
   double minL = SymbolInfoDouble(sym, SYMBOL_VOLUME_MIN), maxL = SymbolInfoDouble(sym, SYMBOL_VOLUME_MAX), step = SymbolInfoDouble(sym, SYMBOL_VOLUME_STEP);
   if(tickSize <= 0 || tickVal <= 0 || step <= 0 || stopDist <= 0) return 0;
   double balance = AccountInfoDouble(ACCOUNT_BALANCE);
   double lossPerLot = stopDist / tickSize * tickVal;
   double wanted = balance * riskPct / 100.0;
   double total = wanted / lossPerLot;
   double half = MathFloor(total / 2.0 / step + 1e-9) * step;
   if(half < minL)
   {
      half = minL;
      double real = half * 2.0 * lossPerLot;
      if(real > wanted * MaxOverRiskFactor) { realRiskPct = real / balance * 100.0; return 0; }
   }
   if(half > maxL) half = maxL;
   realRiskPct = half * 2.0 * lossPerLot / balance * 100.0;
   return NormalizeDouble(half, 2);
}

//------------------------------------------------------------------ entries
void TryEnter(const string obj)
{
   long id = StringToInteger(JVal(obj, "id"));
   if(id <= 0 || GlobalVariableCheck(GVn(id, ""))) return;   // already handled once
   if(JVal(obj, "tp1_hit") == "true") return;                // missed the first target already
   string pair = JVal(obj, "pair");
   string dir = JVal(obj, "direction");
   double entry = StringToDouble(JVal(obj, "entry")), sl = StringToDouble(JVal(obj, "stop_loss"));
   double tp1 = StringToDouble(JVal(obj, "tp1")), tp2 = StringToDouble(JVal(obj, "tp2"));
   datetime opened = ParseIso(JVal(obj, "opened_at"));
   if(entry <= 0 || sl <= 0 || tp1 <= 0 || tp2 <= 0) return;
   if(TimeGMT() - opened > MaxSignalAgeMin * 60) return;
   string sym = BrokerSymbol(pair);
   if(sym == "") { Print("FXS: symbol not found at your broker for ", pair, " (use SymbolMap/SymbolSuffix)"); return; }
   bool buy = (dir == "BUY");
   double ask = SymbolInfoDouble(sym, SYMBOL_ASK), bid = SymbolInfoDouble(sym, SYMBOL_BID);
   double stopDist = MathAbs(entry - sl);
   double drift = buy ? (ask - entry) : (entry - bid);          // positive = price moved against a new entry
   if(drift > stopDist * MaxDriftPctOfStop / 100.0) { Print("FXS: #", id, " skipped, price already ran away from entry"); return; }
   if(buy  && (bid <= sl  || bid >= tp1)) return;
   if(!buy && (ask >= sl  || ask <= tp1)) return;
   if(OpenSignalCount() >= MaxOpenSignals) return;
   if(DailyLimitHit()) { g_status = "daily loss limit reached, no new signals"; return; }

   double risk = (RiskPercent > 0 ? RiskPercent : g_appRisk);
   if(risk > MaxRiskPercent) risk = MaxRiskPercent;
   double real = 0;
   double half = HalfLots(sym, stopDist, risk, real);
   if(half <= 0) { Print("FXS: #", id, " skipped, account too small for ", DoubleToString(risk, 1), "% on ", sym, " (min lot would risk ", DoubleToString(real, 1), "%)"); GlobalVariableSet(GVn(id, ""), 0); return; }
   if(!EnableTrading) { Print("FXS: #", id, " ", dir, " ", sym, " ", DoubleToString(half * 2, 2), " lots (watch only)"); return; }

   int dg = (int)SymbolInfoInteger(sym, SYMBOL_DIGITS);
   sl = NormalizeDouble(sl, dg); tp1 = NormalizeDouble(tp1, dg); tp2 = NormalizeDouble(tp2, dg);
   trade.SetExpertMagicNumber(MagicNumber);
   trade.SetDeviationInPoints(SlippagePoints);
   trade.SetTypeFillingBySymbol(sym);
   GlobalVariableSet(GVn(id, ""), 1);
   GlobalVariableSet(GVn(id, "_e"), entry);
   GlobalVariableSet(GVn(id, "_t1"), tp1);
   GlobalVariableSet(GVn(id, "_t2"), tp2);
   int opened_legs = 0;
   for(int leg = 0; leg < 2; leg++)
   {
      string tag = "FXS" + (string)id + (leg == 0 ? "A" : "B");
      double tp = (leg == 0 ? tp1 : tp2);
      bool ok = buy ? trade.Buy(half, sym, 0, sl, tp, tag) : trade.Sell(half, sym, 0, sl, tp, tag);
      if(!ok) { Sleep(500); ok = buy ? trade.Buy(half, sym, 0, sl, tp, tag) : trade.Sell(half, sym, 0, sl, tp, tag); }
      if(ok) opened_legs++; else Print("FXS: ", tag, " failed: ", trade.ResultRetcodeDescription());
   }
   GlobalVariableSet(GVn(id, "_ok"), opened_legs);
   Print("FXS: #", id, " ", dir, " ", sym, " opened ", opened_legs, "/2 trades, ", DoubleToString(half, 2), " lots each, real risk ", DoubleToString(real, 1), "%");
}

//------------------------------------------------------------------ management
bool MoveStop(const ulong ticket, const double newSl)
{
   if(!PositionSelectByTicket(ticket)) return false;
   string sym = PositionGetString(POSITION_SYMBOL);
   bool buy = (PositionGetInteger(POSITION_TYPE) == POSITION_TYPE_BUY);
   double curSl = PositionGetDouble(POSITION_SL), curTp = PositionGetDouble(POSITION_TP);
   int dg = (int)SymbolInfoInteger(sym, SYMBOL_DIGITS);
   double ns = NormalizeDouble(newSl, dg);
   if(curSl != 0 && ((buy && ns <= curSl) || (!buy && ns >= curSl))) return true;      // never loosen
   double pt = SymbolInfoDouble(sym, SYMBOL_POINT);
   double lvl = (double)SymbolInfoInteger(sym, SYMBOL_TRADE_STOPS_LEVEL) * pt;
   double bid = SymbolInfoDouble(sym, SYMBOL_BID), ask = SymbolInfoDouble(sym, SYMBOL_ASK);
   if(buy && bid - ns < lvl) return false;
   if(!buy && ns - ask < lvl) return false;
   return trade.PositionModify(ticket, ns, curTp);
}

void ManagePositions()
{
   for(int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong t = PositionGetTicket(i);
      if(t == 0 || PositionGetInteger(POSITION_MAGIC) != MagicNumber) continue;
      string cm = PositionGetString(POSITION_COMMENT);
      if(StringLen(cm) < 5 || StringSubstr(cm, 0, 3) != "FXS" || StringSubstr(cm, StringLen(cm) - 1, 1) != "B") continue;
      long id = StringToInteger(StringSubstr(cm, 3, StringLen(cm) - 4));
      if(!GlobalVariableCheck(GVn(id, "_t1"))) continue;
      double entry = GlobalVariableGet(GVn(id, "_e")), tp1 = GlobalVariableGet(GVn(id, "_t1")), tp2 = GlobalVariableGet(GVn(id, "_t2"));
      string sym = PositionGetString(POSITION_SYMBOL);
      bool buy = (PositionGetInteger(POSITION_TYPE) == POSITION_TYPE_BUY);
      double bid = SymbolInfoDouble(sym, SYMBOL_BID), ask = SymbolInfoDouble(sym, SYMBOL_ASK);
      double openPrice = PositionGetDouble(POSITION_PRICE_OPEN);

      ulong ta;
      bool tp1Done = (GlobalVariableGet(GVn(id, "_ok")) >= 2 && !FindPos(id, "A", ta)) || GlobalVariableCheck(GVn(id, "_srv1"));
      double mid = (tp1 + tp2) / 2.0;
      bool halfway = buy ? (bid >= mid) : (ask <= mid);
      if(tp1Done && halfway) MoveStop(t, tp1);                      // lock TP1 profit
      else if(tp1Done) MoveStop(t, openPrice);                      // back to entry
   }
}

//------------------------------------------------------------------ server sync
bool Fetch(string &body)
{
   char post[], res[];
   string hdr = "X-EA-Key: " + ApiKey + "\r\nAccept: application/json\r\n", rh;
   ArrayResize(post, 0);
   ResetLastError();
   int code = WebRequest("GET", ApiUrl, hdr, 8000, post, res, rh);
   if(code == -1)
   {
      g_status = "WebRequest blocked: add " + ApiUrl + " in Tools > Options > Expert Advisors (error " + (string)GetLastError() + ")";
      return false;
   }
   if(code == 401) { g_status = "key not accepted: copy it again from the app"; return false; }
   if(code != 200) { g_status = "server answered " + (string)code; return false; }
   body = CharArrayToString(res, 0, WHOLE_ARRAY, CP_UTF8);
   return true;
}

void CloseTag(const long id)
{
   string legs[2] = {"A", "B"};
   for(int l = 0; l < 2; l++)
   {
      ulong t;
      if(FindPos(id, legs[l], t)) trade.PositionClose(t, SlippagePoints);
   }
}

void Poll()
{
   string body;
   if(!Fetch(body)) return;
   g_lastOk = TimeCurrent();
   g_strategy = JVal(body, "strategy");
   double r = StringToDouble(JVal(body, "risk_pct")); if(r > 0) g_appRisk = r;
   g_status = "connected";

   string sigs[], closed[];
   int n = JObjects(body, "signals", sigs);
   for(int i = 0; i < n; i++)
   {
      long id = StringToInteger(JVal(sigs[i], "id"));
      if(JVal(sigs[i], "tp1_hit") == "true" && GlobalVariableCheck(GVn(id, "_t1"))) GlobalVariableSet(GVn(id, "_srv1"), 1);
      TryEnter(sigs[i]);
   }
   int m = JObjects(body, "closed", closed);
   for(int i = 0; i < m; i++)
   {
      long id = StringToInteger(JVal(closed[i], "id"));
      if(GlobalVariableCheck(GVn(id, "_t1"))) CloseTag(id);     // signal ended on the server: leave nothing open
   }
}

void ShowPanel()
{
   Comment("FX Signal bot\n",
           "Status: ", g_status, "\n",
           "Strategy: ", g_strategy, "   Risk: ", DoubleToString((RiskPercent > 0 ? MathMin(RiskPercent, MaxRiskPercent) : MathMin(g_appRisk, MaxRiskPercent)), 1), "%\n",
           "Open signals: ", OpenSignalCount(), "   Last sync: ", (g_lastOk == 0 ? "never" : TimeToString(g_lastOk, TIME_SECONDS)), "\n",
           (EnableTrading ? "" : "WATCH ONLY - trading is switched off\n"));
}

//------------------------------------------------------------------ events
int OnInit()
{
   if(StringLen(ApiKey) != 32) { Alert("FX Signal bot: paste your 32-character bot key from the app into ApiKey."); return INIT_PARAMETERS_INCORRECT; }
   if(AccountInfoInteger(ACCOUNT_MARGIN_MODE) != ACCOUNT_MARGIN_MODE_RETAIL_HEDGING)
   { Alert("FX Signal bot needs a HEDGING account (two trades per signal). This account is netting."); return INIT_FAILED; }
   if(!TerminalInfoInteger(TERMINAL_TRADE_ALLOWED)) Print("FXS: turn on Algo Trading in the toolbar.");
   EventSetTimer(MathMax(10, PollSeconds));
   Poll(); ManagePositions(); ShowPanel();
   return INIT_SUCCEEDED;
}

void OnDeinit(const int reason) { EventKillTimer(); Comment(""); }

void OnTimer() { Poll(); ManagePositions(); ShowPanel(); }

void OnTick() { ManagePositions(); }   // react to the halfway price straight away, not only every poll

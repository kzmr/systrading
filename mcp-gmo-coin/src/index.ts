import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { GmoClient } from "./gmo-client.js";

const gmo = new GmoClient(
  process.env.GMO_API_KEY,
  process.env.GMO_API_SECRET
);

const server = new McpServer({
  name: "gmo-coin",
  version: "0.1.0",
});

// ============================================
// Public API Tools
// ============================================

server.tool(
  "get_ticker",
  "現在のティッカー情報を取得（価格・bid/ask・出来高）。symbolを省略すると全銘柄を返す。",
  { symbol: z.string().optional().describe("通貨ペア（例: BTC, ETH, XRP）省略時は全銘柄") },
  async ({ symbol }) => {
    const data = await gmo.getTicker(symbol);
    return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
  }
);

server.tool(
  "get_klines",
  "ローソク足データを取得。intervalは1min/5min/10min/15min/30min/1hour/4hour/8hour/12hour/1day/1week/1month。dateはYYYYMMDD形式。",
  {
    symbol: z.string().describe("通貨ペア（例: BTC, ETH, XRP）"),
    interval: z.string().describe("時間足（例: 1min, 5min, 1hour, 1day）"),
    date: z.string().describe("日付（YYYYMMDD形式）"),
  },
  async ({ symbol, interval, date }) => {
    const data = await gmo.getKlines(symbol, interval, date);
    return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
  }
);

server.tool(
  "get_orderbooks",
  "板情報（買い注文・売り注文の一覧）を取得",
  { symbol: z.string().describe("通貨ペア（例: BTC, ETH, XRP）") },
  async ({ symbol }) => {
    const data = await gmo.getOrderbooks(symbol);
    return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
  }
);

server.tool(
  "get_trades",
  "直近の約定履歴を取得",
  {
    symbol: z.string().describe("通貨ペア（例: BTC, ETH, XRP）"),
    page: z.number().optional().describe("ページ番号"),
    count: z.number().optional().describe("取得件数（デフォルト100、最大100）"),
  },
  async ({ symbol, page, count }) => {
    const data = await gmo.getTrades(symbol, page, count);
    return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
  }
);

server.tool(
  "get_exchange_status",
  "取引所のステータス（メンテナンス状態など）を取得",
  {},
  async () => {
    const data = await gmo.getStatus();
    return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
  }
);

server.tool(
  "get_symbols",
  "取扱銘柄の一覧と詳細情報（最小注文数量、手数料など）を取得",
  {},
  async () => {
    const data = await gmo.getSymbols();
    return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
  }
);

// ============================================
// Private API Tools
// ============================================

server.tool(
  "get_account_assets",
  "口座の資産残高を取得（JPY残高、各仮想通貨の保有量）",
  {},
  async () => {
    const data = await gmo.getAccountAssets();
    return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
  }
);

server.tool(
  "get_active_orders",
  "未約定の注文一覧を取得",
  { symbol: z.string().describe("通貨ペア（例: BTC, ETH, XRP）") },
  async ({ symbol }) => {
    const data = await gmo.getActiveOrders(symbol);
    return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
  }
);

server.tool(
  "get_latest_executions",
  "直近の約定履歴（自分の取引）を取得",
  {
    symbol: z.string().describe("通貨ペア（例: BTC, ETH, XRP）"),
    count: z.number().optional().describe("取得件数"),
  },
  async ({ symbol, count }) => {
    const data = await gmo.getLatestExecutions(symbol, count);
    return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
  }
);

server.tool(
  "place_order",
  "注文を発注する。executionTypeはMARKET（成行）/LIMIT（指値）/STOP（逆指値）。",
  {
    symbol: z.string().describe("通貨ペア（例: BTC, ETH, XRP）"),
    side: z.enum(["BUY", "SELL"]).describe("売買区分"),
    executionType: z.enum(["MARKET", "LIMIT", "STOP"]).describe("注文タイプ"),
    size: z.string().describe("注文数量（文字列）"),
    price: z.string().optional().describe("価格（LIMIT/STOPの場合必須）"),
  },
  async ({ symbol, side, executionType, size, price }) => {
    const timeInForce =
      executionType === "STOP" ? "FAK" : undefined;
    const data = await gmo.placeOrder(
      symbol,
      side,
      executionType,
      size,
      price,
      timeInForce
    );
    return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
  }
);

server.tool(
  "cancel_order",
  "注文をキャンセルする",
  { orderId: z.string().describe("注文ID") },
  async ({ orderId }) => {
    const data = await gmo.cancelOrder(orderId);
    return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
  }
);

// ============================================
// Start server
// ============================================

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
}

main().catch((err) => {
  console.error("MCP Server error:", err);
  process.exit(1);
});

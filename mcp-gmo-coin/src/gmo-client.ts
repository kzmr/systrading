import crypto from "node:crypto";

const PUBLIC_BASE_URL = "https://api.coin.z.com/public";
const PRIVATE_BASE_URL = "https://api.coin.z.com/private";

interface GmoApiResponse {
  status: number;
  data: unknown;
  responsetime: string;
  messages?: Array<{ message_code: string; message_string: string }>;
}

export class GmoClient {
  private apiKey: string;
  private apiSecret: string;

  constructor(apiKey?: string, apiSecret?: string) {
    this.apiKey = apiKey ?? "";
    this.apiSecret = apiSecret ?? "";
  }

  // ============================================
  // Public API
  // ============================================

  async getTicker(symbol?: string): Promise<unknown> {
    const params = symbol ? `?symbol=${symbol}` : "";
    return this.publicGet(`/v1/ticker${params}`);
  }

  async getKlines(
    symbol: string,
    interval: string,
    date: string
  ): Promise<unknown> {
    return this.publicGet(
      `/v1/klines?symbol=${symbol}&interval=${interval}&date=${date}`
    );
  }

  async getOrderbooks(symbol: string): Promise<unknown> {
    return this.publicGet(`/v1/orderbooks?symbol=${symbol}`);
  }

  async getTrades(symbol: string, page?: number, count?: number): Promise<unknown> {
    let params = `?symbol=${symbol}`;
    if (page) params += `&page=${page}`;
    if (count) params += `&count=${count}`;
    return this.publicGet(`/v1/trades${params}`);
  }

  async getStatus(): Promise<unknown> {
    return this.publicGet("/v1/status");
  }

  async getSymbols(): Promise<unknown> {
    return this.publicGet("/v1/symbols");
  }

  // ============================================
  // Private API
  // ============================================

  async getAccountMargin(): Promise<unknown> {
    return this.privateGet("/v1/account/margin");
  }

  async getAccountAssets(): Promise<unknown> {
    return this.privateGet("/v1/account/assets");
  }

  async getActiveOrders(symbol: string): Promise<unknown> {
    return this.privateGet(`/v1/activeOrders?symbol=${symbol}`);
  }

  async getExecutions(orderId?: string, executionId?: string): Promise<unknown> {
    let params = "";
    if (orderId) params = `?orderId=${orderId}`;
    else if (executionId) params = `?executionId=${executionId}`;
    return this.privateGet(`/v1/executions${params}`);
  }

  async getLatestExecutions(symbol: string, count?: number): Promise<unknown> {
    let params = `?symbol=${symbol}`;
    if (count) params += `&count=${count}`;
    return this.privateGet(`/v1/latestExecutions${params}`);
  }

  async placeOrder(
    symbol: string,
    side: "BUY" | "SELL",
    executionType: "MARKET" | "LIMIT" | "STOP",
    size: string,
    price?: string,
    timeInForce?: string
  ): Promise<unknown> {
    const body: Record<string, string> = {
      symbol,
      side,
      executionType,
      size,
    };
    if (price) body.price = price;
    if (timeInForce) body.timeInForce = timeInForce;
    return this.privatePost("/v1/order", body);
  }

  async cancelOrder(orderId: string): Promise<unknown> {
    return this.privatePost("/v1/cancelOrder", { orderId });
  }

  // ============================================
  // HTTP helpers
  // ============================================

  private async publicGet(path: string): Promise<unknown> {
    const url = `${PUBLIC_BASE_URL}${path}`;
    const res = await fetch(url, {
      headers: { "Content-Type": "application/json" },
    });
    const data = (await res.json()) as GmoApiResponse;
    if (data.status !== 0) {
      throw new Error(
        `GMO API Error: ${JSON.stringify(data.messages ?? data.status)}`
      );
    }
    return data.data;
  }

  private async privateGet(path: string): Promise<unknown> {
    this.ensureAuth();
    const timestamp = Date.now().toString();
    const text = timestamp + "GET" + path;
    const sign = this.createSign(text);

    const url = `${PRIVATE_BASE_URL}${path}`;
    const res = await fetch(url, {
      headers: {
        "API-KEY": this.apiKey,
        "API-TIMESTAMP": timestamp,
        "API-SIGN": sign,
        "Content-Type": "application/json",
      },
    });
    const data = (await res.json()) as GmoApiResponse;
    if (data.status !== 0) {
      throw new Error(
        `GMO API Error: ${JSON.stringify(data.messages ?? data.status)}`
      );
    }
    return data.data;
  }

  private async privatePost(
    path: string,
    body: Record<string, string>
  ): Promise<unknown> {
    this.ensureAuth();
    const timestamp = Date.now().toString();
    const bodyStr = JSON.stringify(body);
    const text = timestamp + "POST" + path + bodyStr;
    const sign = this.createSign(text);

    const url = `${PRIVATE_BASE_URL}${path}`;
    const res = await fetch(url, {
      method: "POST",
      headers: {
        "API-KEY": this.apiKey,
        "API-TIMESTAMP": timestamp,
        "API-SIGN": sign,
        "Content-Type": "application/json",
      },
      body: bodyStr,
    });
    const data = (await res.json()) as GmoApiResponse;
    if (data.status !== 0) {
      throw new Error(
        `GMO API Error: ${JSON.stringify(data.messages ?? data.status)}`
      );
    }
    return data.data;
  }

  private createSign(text: string): string {
    return crypto
      .createHmac("sha256", this.apiSecret)
      .update(text)
      .digest("hex");
  }

  private ensureAuth(): void {
    if (!this.apiKey || !this.apiSecret) {
      throw new Error(
        "API key and secret are required for private API calls. " +
          "Set GMO_API_KEY and GMO_API_SECRET environment variables."
      );
    }
  }
}

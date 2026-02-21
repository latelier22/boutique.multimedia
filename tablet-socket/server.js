import express from "express";
import http from "http";
import { WebSocketServer } from "ws";

const PORT = process.env.TABLET_SOCKET_PORT || 3010;
const TOKEN = process.env.TABLET_SOCKET_TOKEN || "CHANGE_ME";

const app = express();
app.use(express.urlencoded({ extended: true }));
app.use(express.json());

const server = http.createServer(app);
const wss = new WebSocketServer({ server });

/** device => ws */
const clients = new Map();

function okToken(t) {
  return t && t === TOKEN;
}

wss.on("connection", (ws, req) => {
  const url = new URL(req.url, "http://localhost");
  const device = url.searchParams.get("device") || "TAB1";
  const token = url.searchParams.get("token");

  if (!okToken(token)) {
    ws.close(1008, "bad token");
    return;
  }

  clients.set(device, ws);
  console.log("[WS] connected", device);

  ws.on("close", () => {
    if (clients.get(device) === ws) clients.delete(device);
    console.log("[WS] closed", device);
  });

  ws.on("message", (msg) => {
    // optionnel : debug
    // console.log("[WS] msg", device, msg.toString());
  });
});

/**
 * POST /push (device, url, token)
 * pousse vers 1 device
 */
app.post("/push", (req, res) => {
  const { device = "TAB1", url, token } = req.body || req;
  if (!okToken(token)) return res.status(403).json({ ok: false, error: "bad token" });
  if (!url) return res.status(400).json({ ok: false, error: "missing url" });

  const ws = clients.get(device);
  if (!ws || ws.readyState !== 1) {
    return res.json({ ok: false, error: "tablet not connected", device });
  }

  ws.send(JSON.stringify({ url }));
  return res.json({ ok: true, device, url });
});

/**
 * GET /health
 */
app.get("/health", (_req, res) => res.json({ ok: true, connected: [...clients.keys()] }));

server.listen(PORT, "127.0.0.1", () => {
  console.log(`[HTTP+WS] listening on 127.0.0.1:${PORT}`);
});

// ponytail: test script to invoke MCP tools directly
import { spawn } from "child_process";

const child = spawn("node", ["index.js"], { stdio: ["pipe", "pipe", "inherit"] });

let output = "";
child.stdout.on("data", (chunk) => {
  output += chunk.toString();
  try {
    const lines = output.trim().split("\n");
    for (const line of lines) {
      if (line.includes('"result"')) {
        console.log("✅ MCP Response Received:\n");
        const json = JSON.parse(line);
        console.log(JSON.stringify(json, null, 2));
        child.kill();
        process.exit(0);
      }
    }
  } catch {
    // wait for full json line
  }
});

// Send JSON-RPC 2.0 initialize request
const initReq = JSON.stringify({
  jsonrpc: "2.0",
  id: 1,
  method: "initialize",
  params: {
    protocolVersion: "2024-11-05",
    capabilities: {},
    clientInfo: { name: "test-client", version: "1.0.0" }
  }
}) + "\n";

// Send tools/list request
const listToolsReq = JSON.stringify({
  jsonrpc: "2.0",
  id: 2,
  method: "tools/list",
  params: {}
}) + "\n";

child.stdin.write(initReq);
child.stdin.write(listToolsReq);

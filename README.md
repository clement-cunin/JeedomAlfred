# Alfred — Jeedom AI Agent

Alfred is a Jeedom plugin that embeds an AI agent directly into your home automation interface. Talk to your installation in natural language — Alfred uses [JeedomMCP](https://github.com/clement-cunin/JeedomMCP) to control devices, run scenarios and read sensor states.

## Features

- **Multi-provider**: Anthropic (Claude), OpenAI (GPT-4o), Google (Gemini)
- **Agentic**: the LLM calls JeedomMCP tools in a ReAct loop to act on your system
- **Streaming**: responses stream in real time via Server-Sent Events
- **Mobile-first**: responsive UI, installable as a PWA on iOS & Android
- **Pure PHP**: no Python daemon, no background process — same architecture as JeedomMCP

## Requirements

- Jeedom ≥ 4.4
- [JeedomMCP](https://github.com/clement-cunin/JeedomMCP) plugin installed and configured
- An API key for at least one LLM provider (Anthropic, OpenAI or Gemini)

## Installation

Install via the Jeedom Market (beta), or clone this repository into `/var/www/html/plugins/alfred/`.

## Configuration

1. Open the Alfred plugin configuration
2. Select your LLM provider and enter the corresponding API key
3. Choose the model
4. Verify that the JeedomMCP URL and API key are correct (auto-detected on first activation)
5. Optionally customise the system prompt
6. Save, then open the plugin page to start chatting

## Architecture

```
Browser ←── SSE ──→ api/chat.php
                        │
                    PHP agent loop
                   ┌────┴────┐
              LLM REST API   JeedomMCP (JSON-RPC)
```

No daemon. PHP handles LLM streaming via `curl` with `CURLOPT_WRITEFUNCTION`, re-streams chunks to the browser as SSE events.

## Development phases

| Phase | Content | Status |
|-------|---------|--------|
| 1 | Plugin skeleton, config page, SQL, chat UI structure | ✅ Done |
| 2 | LLM integration (non-streaming) + provider adapters | 🔜 |
| 3 | Agent loop (ReAct) + conversation history | 🔜 |
| 4 | SSE streaming endpoint | 🔜 |
| 5 | Full chat UI + PWA | 🔜 |

## License

AGPL-3.0

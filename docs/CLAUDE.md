# CLAUDE.md — Battlesnake AI (PHP)

This file lives in **`docs/`** next to `TDD.md` so the repo root stays minimal. Use this path when prompting assistants (`@docs/CLAUDE.md`).

## Project Summary

A PHP 8.3 Battlesnake server that uses two OpenRouter LLM models raced simultaneously to
select moves. A flood-fill safety layer and MCTS fallback guarantee a legal move is always
returned within 450ms. Runs in Docker on a System76 Thelio. Public access uses Cloudflare
Tunnel: `cloudflared` runs on the Thelio host (already configured), not as a Docker sidecar;
map the tunnel to **`http://127.0.0.1:9595`** when you expose the stack (host port **9595** → container nginx on **9000**, so this snake does not collide with other services using 9000 on the host).

See `docs/TDD.md` for the full design specification.

---

## Commands

```bash
# Install dependencies
composer install

# Run tests
composer test
# or directly:
./vendor/bin/phpunit tests/

# Run with coverage
./vendor/bin/phpunit --coverage-text tests/

# Start locally (Docker)
docker compose up -d

# View logs
docker compose logs -f snake

# Rebuild after code changes
docker compose build snake && docker compose up -d snake

# Smoke test (host port 9595 → nginx :9000 in the container)
curl http://localhost:9595/
curl -X POST http://localhost:9595/move \
  -H "Content-Type: application/json" \
  -d @tests/Fixtures/sample_board.json

# Stop
docker compose down
```

---

## File Structure

```
battlesnake-ai/
├── docs/
│   ├── TDD.md                 # Full design specification
│   └── CLAUDE.md              # This file — build order and conventions
├── public/
│   └── index.php              # Front controller — routes all 4 endpoints
├── src/
│   ├── Board.php              # Board::format(array $state): string
│   ├── Safety.php             # legalMoves(), floodFill(), mctsMove()
│   ├── OpenRouter.php         # race(string $prompt, array $safeMoves): ?string
│   ├── Prompts.php            # Prompts::SYSTEM constant
│   └── Logger.php             # Logger::move(array $data): void
├── tests/
│   ├── Fixtures/
│   │   └── sample_board.json  # Realistic 11x11 board state for smoke testing
│   ├── BoardTest.php
│   ├── SafetyTest.php
│   └── OpenRouterTest.php
├── nginx/
│   └── default.conf           # nginx site config (fastcgi_pass to php-fpm unix socket)
├── Dockerfile                 # Multi-stage: composer install → nginx + php-fpm runtime
├── docker-compose.yml         # snake; publish 127.0.0.1:9595:9000 when tunnel / curls need it
├── composer.json
├── .env.example
├── .env                       # gitignored — copy from .env.example and fill in
├── .gitignore
```

---

## Key Conventions

- **PHP 8.3+** — use typed properties, readonly classes, match expressions, named arguments.
  Do not use fibers; curl_multi is sufficient for two parallel HTTP requests.
- **No framework** — plain PHP with a hand-rolled front controller in `public/index.php`.
  Do not introduce Slim, Laravel, Symfony, or any other framework.
- **No database, no sessions, no state** — every `/move` call is fully stateless.
- **curl_multi for parallel HTTP** — do not introduce Guzzle, ReactPHP, Swoole, or any
  async extension. curl_multi is built in and has zero dependencies.
- **Strict types** — `declare(strict_types=1)` at the top of every PHP file.
- **Error handling** — never let an exception propagate to the game engine. Wrap all
  OpenRouter calls in try/catch. The worst acceptable response to the game engine is
  `{"move":"up"}`. The game engine must always receive a 200 OK.
- **Logging** — all logs go to stdout as JSON lines (Docker captures them). Use
  `Logger::move()` for move decisions. Use `error_log()` for unexpected exceptions.

---

## Coordinate System

Battlesnake uses `(0,0)` = bottom-left corner. `y` increases upward. `x` increases rightward.

`Board::format()` flips the y-axis when rendering the ASCII grid so that row 0 in the output
corresponds to the top of the board (`y = height - 1`). This matches human reading direction
(top-to-bottom) and helps the LLM reason correctly about spatial relationships.

---

## Board Legend

```
H  = own head
B  = own body
T  = own tail (will vacate next turn unless we just ate)

e+ = enemy head — snake is shorter than us (safe to pursue head-on kill)
e= = enemy head — snake is equal length (head-on is mutual death, avoid)
e- = enemy head — snake is longer than us (must avoid head-on)
s  = enemy body segment
t  = enemy tail (will vacate next turn unless that snake just ate)

F  = food
X  = hazard (sauce)
.  = empty
```

---

## OpenRouter Racing Contract

`OpenRouter::race()` fires `PRIMARY_MODEL` immediately and `SECONDARY_MODEL` after
`STAGGER_MS` (default 50ms). It polls via `curl_multi_exec` every 5ms. The first handle to
return a valid JSON response containing a move present in `$safeMoves` wins. The losing handle
is closed immediately. If neither returns a valid move before `TIMEOUT_MS` elapses, `race()`
returns `null` and the caller falls through to MCTS.

Both models are called with `response_format: json_object` to prevent markdown fences.
`max_tokens` is 60 — a move plus one sentence of reasoning fits comfortably; more wastes time.

---

## Safety Contract

`Safety::legalMoves()` always returns a non-empty array, sorted descending by flood-fill
score (most open space first). The last element is the "least-bad" fallback move. The caller
must never pass an empty array to `mctsMove()`.

A move is **illegal** if any of the following are true:

- It would exit the board boundary (standard 11×11, no wrapping).
- It would land on any snake's body segment (own or enemy) that is not the tail,
  OR it is the tail AND that snake ate food on the immediately preceding turn.
- It would cause a head-to-head collision with a snake of equal or greater length.

A move that passes the above checks but leads to a flood-fill space smaller than
`own_length × 0.5` is flagged as poor but still included in the returned array as a
last-resort option (snake is nearly trapped; any legal move is better than nothing).

---

## Prior Snake Reference Files

The following files from prior Battlesnake implementations contain board serialization logic.
Review them for coordinate conventions and cell encoding before implementing `Board::format()`.
Note any differences from the legend and coordinate system defined above, and reconcile them
explicitly in the new implementation.

```
[PLACEHOLDER: relative path to v1 snake board serialization file]
[PLACEHOLDER: relative path to v2 snake board serialization file]
```

---

## Environment Variables

Copy `.env.example` to `.env` and fill in all values before running:

```
OPENROUTER_API_KEY=sk-or-...

PRIMARY_MODEL=google/gemini-2.0-flash
SECONDARY_MODEL=anthropic/claude-haiku-4-5
LLM_TIMEOUT_MS=300
STAGGER_MS=50

SNAKE_COLOR=#1a1a2e
SNAKE_HEAD=tongue
SNAKE_TAIL=bolt
```

---

## Docker Notes

- The `snake` service runs nginx + php-fpm in a single container. nginx listens on `:9000`
  and proxies to php-fpm via a unix socket.
- **Cloudflare:** `cloudflared` runs on the Thelio host (not in Compose). When you want
  `snake.eamann.com` to hit the container, publish **`127.0.0.1:9595:9000`** and point the
  existing tunnel ingress at **`http://127.0.0.1:9595`** in your tunnel / Zero Trust config.
- Thelio management from Chicago is via Tailscale SSH only.
- nginx `keepalive_timeout` must be set to at least 65s (Cloudflare drops connections idle
  for more than 60s).
- php-fpm pool: `pm = dynamic`, `pm.max_children = 16`, `pm.start_servers = 4`.

---

## Test Fixtures

`tests/Fixtures/sample_board.json` must be a realistic 11×11 board state containing:

- At least 2 enemy snakes of varying lengths (one shorter, one longer than the player)
- Food items in at least 3 locations
- A hazard sauce zone covering part of the board
- The player snake with `health < 50` (exercises the food-seeking path in the prompt)
- The player snake in a position where at least 2 legal moves exist (exercises move selection)

---

## Build This In Order

Work through the stories below sequentially. Do not proceed to the next story until the
current story's acceptance criteria are fully met.

**Story 1 — Scaffold**
- `composer.json` with autoload PSR-4 (`BattlesnakeAI\` → `src/`) and require-dev phpunit ^11
- `Dockerfile` (multi-stage: builder installs Composer deps; runtime is `php:8.3-fpm-alpine`
  with nginx installed, unix socket between nginx and fpm)
- `nginx/default.conf` (root `/var/www/public`, fastcgi_pass to fpm socket, index.php)
- `docker-compose.yml` with `snake` (build: .) only; publish **`127.0.0.1:9595:9000`**
  when wiring the host tunnel or local smoke tests to the stack
- `.env.example` with all variables listed above
- `.gitignore` (vendor/, .env, *.log)
- _Acceptance:_ `docker compose build` completes without error.

**Story 2 — Front controller**
- `public/index.php` routes `GET /`, `POST /start`, `POST /end`, `POST /move`
- `GET /` returns hardcoded snake metadata JSON (reads SNAKE_* from env)
- `POST /start` and `POST /end` return `{}`
- `POST /move` returns `{"move":"up"}` (stub)
- Any other path returns 404
- _Acceptance:_ `curl http://localhost:9595/` returns valid Battlesnake metadata JSON.

**Story 3 — Safety layer**
- `src/Safety.php` with `legalMoves()`, `floodFill()`, `mctsMove()`
- `tests/SafetyTest.php` with unit tests covering: wall collision, body collision,
  head-on avoidance, flood fill correctness on a known board, MCTS returns a legal move
- _Acceptance:_ All SafetyTest tests pass. Snake can play a full game returning only
  flood-fill-selected moves (wire temporarily into index.php stub to verify).

**Story 4 — Board serialization**
- `src/Board.php` with `Board::format(array $state): string`
- Reference the prior snake files listed in the Prior Snake Reference Files section above
- `tests/BoardTest.php` with snapshot tests: given a known game state array, assert the
  formatted string matches the expected ASCII output exactly
- _Acceptance:_ BoardTest passes. Manually verify that a sample board string reads
  intuitively when printed (top = high y, bottom = low y).

**Story 5 — Prompts**
- `src/Prompts.php` defining `const SYSTEM` with the full system prompt
- Prompt must include: role statement, full board legend, coordinate system, death
  conditions, tail vacate rule, strategic priority list (6 items), response format
  requirement (JSON only, no fences: `{"move":"...","reasoning":"..."}`)
- _Acceptance:_ Manually POST a sample board string to OpenRouter using the system prompt;
  verify 10 consecutive responses are valid JSON containing a legal move.

**Story 6 — OpenRouter racing client**
- `src/OpenRouter.php` with `race()`, `buildHandle()`, `parseMove()`
- Use a swappable transport interface so tests can inject mock curl responses without
  hitting the network
- `tests/OpenRouterTest.php` covering: primary wins, secondary wins, both time out
  (returns null), LLM returns illegal move (returns null), LLM returns unparseable JSON
  (returns null)
- _Acceptance:_ All OpenRouterTest tests pass with mocked transport.

**Story 7 — Logger**
- `src/Logger.php` with `Logger::move(array $data): void` writing a single JSON line
  to stdout via `echo` (not error_log)
- Fields: `ts`, `game_id`, `turn`, `model_used`, `move`, `reasoning`, `safe_moves`,
  `llm_latency_ms`, `total_latency_ms`, `fallback_used`, `own_health`, `own_length`
- _Acceptance:_ `docker compose logs -f snake` shows one JSON line per `/move` call.

**Story 8 — Wire together**
- Update `public/index.php` `/move` handler with the full flow:
  parse → validate → `Safety::legalMoves()` → `Board::format()` → `OpenRouter::race()`
  → `Safety::mctsMove()` fallback → `Logger::move()` → return JSON
- _Acceptance:_ Play a full game via the Battlesnake web UI. Logs show `model_used` and
  `llm_latency_ms` on turns where the LLM responded in time.

**Story 9 — Docker hardening and smoke test**
- nginx: `client_max_body_size 1m`, `keepalive_timeout 65`, appropriate fastcgi timeouts
- php-fpm pool config as specified in Docker Notes above
- Docker health check: `CMD curl --fail http://localhost:9000/ || exit 1` (inside the container nginx still listens on **9000**)
- Run smoke test from the host: `curl -X POST http://localhost:9595/move -d @tests/Fixtures/sample_board.json`
- _Acceptance:_ Container reports healthy. Smoke test returns a valid move JSON within 500ms.
  `docker compose up -d` on Thelio;   with the host tunnel mapped to **`http://127.0.0.1:9595`**, snake responds correctly at
  `https://snake.eamann.com`.
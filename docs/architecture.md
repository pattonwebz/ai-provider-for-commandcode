# AI Provider for Command Code — Architecture & Implementation Plan

> **Status:** Implemented (v0.1.0); kept as the living architecture reference.
> **Repo target:** `pattonwebz/ai-provider-for-commandcode` (GitHub, public)
> **Companion reference repos (local clones used for this research):**
> `/opt/data/wp-ai/php-ai-client` (SDK trunk), `/opt/data/wp-ai/openai-ai-provider` (official provider plugin template), `/opt/data/wp-ai/wporg-develop` (WP core 7.0 branch, `src/wp-includes` sparse checkout)

**Goal:** A WordPress plugin — "AI Provider for Command Code" — that registers Command Code's Provider API as a first-class AI provider in the WordPress 7.0 AI Connectors system, mirroring the official `AI Provider for OpenAI` plugin's architecture.

**Architecture:** The plugin is a thin WordPress wrapper around a provider module for the `wordpress/php-ai-client` SDK (bundled in WP core since 7.0). It registers one provider class into the SDK's provider registry; WP core's Connectors API auto-discovers it and renders a "Command Code" card under Settings → Connectors. All requests use Command Code's OpenAI-compatible `/provider/v1/chat/completions` endpoint.

**Tech stack:** PHP ≥ 7.4, WordPress ≥ 7.0 (AI Client + Connectors API in core), `wordpress/php-ai-client` SDK (core bundles v1.3.1). Composer package + WordPress plugin in one repo (dual-use, like the official provider).

---

# Part A — How the WordPress AI connector system works (verified ground truth)

Ground truth sources: WP core 7.0 source (`wporg-develop/src/wp-includes/{ai-client.php, connectors.php, class-wp-connector-registry.php, ai-client/, php-ai-client/}`), the official provider plugin, and the SDK.

## A.1 The three layers

1. **Provider plugins** (what we build). Core ships zero providers. Each provider plugin registers one class into the AI Client's *provider registry* at `init` priority 5:

   ```php
   $registry = AiClient::defaultRegistry();
   if ( ! $registry->hasProvider( CommandCodeProvider::class ) ) {
       $registry->registerProvider( CommandCodeProvider::class );
   }
   ```

   Guarded by `class_exists( AiClient::class )` — the SDK classes are loaded by core.

2. **The SDK registry** (`WordPress\AiClient\Providers\ProviderRegistry`) — holds providers, their metadata, and per-provider *request authentication*. It auto-creates auth from the provider's declared auth schema: it reads the env var **or PHP constant** named after the provider id (see A.3). Consuming plugins never see keys.

3. **The Connectors API** (`WP_Connector_Registry`, Settings → Connectors). At init it iterates every registered provider id and builds a connector card from the provider's static metadata: name, description, logo, credentials URL, auth method (`connectors.php` `_wp_connectors_register_default_ai_providers()`). Anthropic/Google/OpenAI additionally have hardcoded fallback entries; **any other provider id is auto-created purely from provider metadata** — no registration code needed in the provider plugin.

## A.2 Per-request model selection ("different models for different request types")

The public entry point is `wp_ai_client_prompt()` → `WP_AI_Client_Prompt_Builder`, which proxies snake_case calls onto the SDK's fluent `PromptBuilder`. Model choice happens **per call, in code**, at three levels:

- **Capability routing (default).** Every model advertises capabilities + input/output modalities (`ModelMetadata`). `generate_text()`, `generate_image()`, `generate_embedding()`, … resolve to a *configured* model that supports the required capability — searched across **all** registered providers. So a text request may land on Command Code's `deepseek/deepseek-v4-flash` while an image request (needing a capability CC doesn't advertise) falls through to another provider, e.g. Google.
- **Preference / hard lock.** `->using_model_preference( 'deepseek/deepseek-v4-flash', 'gpt-5.5' )` (ordered wishlist, first *available* wins, bare id or `[provider_id, model_id]`), `->using_model( ModelInterface )` (exact), `->using_provider( 'commandcode' )` (provider lock).
- **Catalog advertisement (ours to control).** Each `ModelMetadata` declares capabilities, supported options (`temperature`, `maxTokens`, `systemInstruction`, `outputSchema`, `functionDeclarations`, …) and input/output modalities. The client never asks a model for something its metadata doesn't advertise. **Under-advertising is safe; over-advertising causes API errors.** This is the main design pressure point of this plugin.

**Core has no per-connector "model for request type X" admin setting.** Model → request-type mapping is capability-driven + code-driven. The WordPress AI plugin's own features (title/excerpt/alt-text) are ordinary consumers of this API.

**Vision requests are text generation with image *input* — the model *describes/analyzes* an image and returns text; it does not generate images.** A prompt that adds an image part (`with_file()` / `with_message_parts()`) raises an input-modality requirement, and the resolver then considers only models whose metadata advertises the `[text, image]` input combination — text-only models are automatically skipped, so a vision request can never land on `deepseek/deepseek-v4-flash` even if it is first in the sort order. Which vision-capable model wins is then: (1) the consumer's `using_model_preference( ... )` at the call site, or (2) the first vision-capable model in the provider's sorted catalog (fallback). There is **no core filter to override model selection site-wide** (verified in 7.0 core: the only AI filters are `wp_supports_ai`, `wp_ai_client_default_request_timeout`, `wp_ai_client_prevent_prompt`), so a site-wide "vision model" setting would require consumer opt-in or future core support. This plugin therefore controls vision choice via advertisement + default ordering, and documents the call-site recipe (see Part C §8, Task 10).

## A.3 Credential resolution (verified in core code)

For a provider id `X` the names are **derived**, not configured:

- PHP constant / env var: `strtoupper( id, camel→snake ) . '_API_KEY'` → for id `commandcode`: **`COMMANDCODE_API_KEY`**
- DB option: `connectors_ai_{id with -→_}_api_key` → **`connectors_ai_commandcode_api_key`**

Resolution priority at runtime:
1. Env var / constant — read **by the SDK itself** (`ProviderRegistry::createDefaultProviderRequestAuthentication()` reads `getenv()` then `constant()`).
2. DB option — pushed by core at `init` priority 20 (`_wp_connectors_pass_default_keys_to_ai_client()` → `setProviderRequestAuthentication()`) only when no env/constant exists.

The Settings → Connectors UI masks keys and shows the active source; keys in the DB are **not encrypted** (tracked in trac #64789). The connector's "Connected" state is computed via `isProviderConfigured()`, which for API-key providers runs the provider's availability check — for us: `GET /models` with the key.

Note: the SDK bundled in core is **v1.3.1** (`AiClient::VERSION`). The provider plugin must stay compatible with 1.3.1 APIs (description in metadata since 1.2.0, logoPath since 1.3.0 — both safe; embedding interfaces are 1.4.0-only — irrelevant, we ship no embeddings).

## A.4 Provider class anatomy (what a provider plugin must implement)

A provider class extends `WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider` and implements 5 static factories (all verified in the official plugin):

| Factory | Returns | Ours |
|---|---|---|
| `baseUrl()` | string | `https://api.commandcode.ai/provider/v1` |
| `createModel( ModelMetadata, ProviderMetadata )` | `ModelInterface` | `CommandCodeTextGenerationModel` (capability switch) |
| `createProviderMetadata()` | `ProviderMetadata` | id `commandcode`, name `Command Code`, type cloud, credentials URL, auth `apiKey`, description, logo path |
| `createProviderAvailability()` | `ProviderAvailabilityInterface` | `ListModelsApiBasedProviderAvailability` (validates key via `GET /models`) |
| `createModelMetadataDirectory()` | `ModelMetadataDirectoryInterface` | `CommandCodeModelMetadataDirectory` (live model classification) |

Two crucial SDK base classes exist for OpenAI-compatible providers (this is the foundation that makes the plugin small):

- `AbstractOpenAiCompatibleTextGenerationModel` — full Chat Completions wire implementation: request building (messages incl. images as `image_url`, tools, `response_format` json_schema, sampling params), response parsing into `GenerativeAiResult` (incl. `reasoning_content` → thought channel, tool calls, usage). Subclass only implements `createRequest()`. `generateTextResult()` is `final`.
- `AbstractOpenAiCompatibleModelMetadataDirectory` — fetches `GET models` (auth-attached) and calls an abstract `parseResponseToModelMetadataList(Response)`. Subclass implements `createRequest()` + the parser.

**Consequence:** the whole plugin is ~6 PHP files mirroring the official `ai-provider-for-openai` package, with classification logic and base URL as the main deltas.

# Part B — Command Code Provider API facts (all verified live on 2026-09-08)

## B.1 Endpoints

| Endpoint | Wire | Use |
|---|---|---|
| `https://api.commandcode.ai/provider/v1/chat/completions` | OpenAI Chat Completions | **ours (v1)** |
| `https://api.commandcode.ai/provider/v1/messages` | Anthropic Messages | **deferred (claude-* models)** |
| `https://api.commandcode.ai/provider/v1/models` | GET, OpenAI envelope | live catalog + availability check |

Auth: `Authorization: Bearer <key>` (key from Command Code Studio). Errors use the OpenAI envelope (`error.type` ∈ invalid_request/auth/permission/rate_limit/server_error); some upstream errors arrive nested (`error.message` containing a JSON string) — surfaced via the SDK's standard `ResponseException` handling. No image-generation, audio, or embedding endpoints exist → **v1 advertises text generation only**.

## B.2 Live catalog (`GET /models`, 2026-09-08, 67 models)

Response shape (non-standard extras — exploitable):

```json
{ "object": "list", "data": [ {
  "id": "claude-sonnet-5", "object": "model", "created": 1788866435,
  "owned_by": "command-code", "name": "Claude Sonnet 5", "context_length": 1000000
} ] }
```

Observed ids (prefix → vendor; fixture saved at `/tmp/cc_models.json`):

- **Anthropic wire (excluded from v1):** `claude-sonnet-5`, `claude-sonnet-4-6`, `claude-opus-5`, `claude-opus-4-8`, `claude-opus-4-7`, `claude-haiku-4-5-20251001`, `claude-fable-5`, `claude-fable-5-1` (all unprefixed, start `claude-`)
- **Chat wire:** `gpt-5.6-sol/terra/luna`, `gpt-5.5`, `gpt-5.4`, `gpt-5.4-mini`, `gpt-5.3-codex`, `gpt-6-astra` (unprefixed); `deepseek/deepseek-v4-pro|-flash|-flash-vision-exp|-flash-fast`; `google/gemini-3.1-flash-lite` … `gemini-3.8-flash`; `Qwen/Qwen3.6-…`…`Qwen3.8-Max-0902`; `moonshotai/Kimi-K2.5`…`K3`; `MiniMaxAI/MiniMax-M2.5|-M2.7|-M3` (M2.5/M2.7 **text-only** per probe; only **M3** is vision-capable); `xiaomi/mimo-v2.5(-pro)`; `meta/muse-spark-1.1…1.3(-contributor)`; `zai-org/GLM-5…5.3`, `z-ai/glm-5.3-flash`; `xai/grok-4.5|-4.6`; `stepfun/Step-3.5|-3.7-Flash`; `tencent/hy3-paid|hy4-preview`; `thinkingmachines/inkling(-small)`; `sakana/fugu-ultra`; `nvidia/nemotron-3-ultra-550b-a55b`; `poolside/laguna-s-2.1-free`; `meituan/LongCat-2.0:free` (note the `:`)

## B.3 Wire behavior probes (live)

- `claude-sonnet-5` on chat/completions → **HTTP 400** `unsupported_model`: "must be called via /provider/v1/messages". Confirms exclusion rule.
- `gpt-5.4` on chat/completions → params pass through to upstream; `max_tokens` < 16 rejected (upstream min on `max_output_tokens`). Works fine with sane values.
- `deepseek/deepseek-v4-flash` → normal OpenAI response incl. `usage` (with `prompt_tokens_details`, `completion_tokens_details.reasoning_tokens`). Default Command Code model; sensible first model for auto-selection ordering.
- `google/gemini-3.8-flash` → 200, but empty content at `max_tokens: 8` (budget consumed upstream); non-issue with real budgets — worth a README note, not a code change.
- Streaming exists on both routes (not needed in v1; the SDK base does non-streaming requests).
- **Vision probes (live, 1×1 PNG, 2026-09-08):** `MiniMaxAI/MiniMax-M3` → 200, image described → **vision confirmed**. `MiniMaxAI/MiniMax-M2.7` → 400 (upstream routing error shows image content sent to non-vision providers `fireworks, togetherai`) → **text-only**. `deepseek/deepseek-v4-flash-vision-exp` → 200 but empty reply at 32-token budget (reasoning consumed it) → accepted, needs re-probe with real budget. Earlier: `google/gemini-3.8-flash` → 200 with empty reply at `max_tokens: 8` (same reasoning-budget pattern).

# Part C — Design decisions (v1)

1. **Provider id `commandcode`** → auto-derived names: env/constant `COMMANDCODE_API_KEY`, DB `connectors_ai_commandcode_api_key`. (This box's env already has `COMMANDCODE_API_KEY` set — WP sites on this box would work with zero admin config.)
2. **Text generation only.** Capabilities advertised: `textGeneration`, `chatHistory`. No embeddings/images/audio (API doesn't offer them).
3. **Chat Completions wire only; `claude-*` ids excluded** from the catalog by regex (`/^claude-/`). A future v2 can add the Anthropic wire — options: (a) a second provider class (`commandcode-anthropic`, own derived key name — clunky), or (b) a single provider whose per-model implementation switches wire based on model id (cleaner; SDK `createModel()` is capability-based so this needs a combined text-generation model class that checks the model id and posts to `/messages` for claude ids). **Deferred; don't design around it.**
4. **Live-fetch + regex classify** (official plugin pattern): each resolution/refresh fetches `GET /models` and classifies by id regex → `ModelMetadata` with hardcoded capability/option tables + a live display name (`name` field when present, else id).
5. **Classification defaults (conservative = safe):**
   - All chat-wire models: textGeneration + chatHistory; options: systemInstruction, maxTokens, candidateCount, stopSequences, outputMimeType (`text/plain`, `application/json`), outputSchema, functionDeclarations, customOptions; input `[text]`, output `[text]`.
   - Sampling options (`temperature`, `topP`) **only for families verified to accept them** — see Task 8 (live probe) — initial assumption: open-weight families accept; `gpt-*` reasoning family may reject (OpenAI behavior) and needs probing.
   - Image input `[text, image]` — i.e. **vision**: model describes/analyzes an image and returns text (NEVER image generation — Command Code's API offers no image-output endpoint). Advertised **only for families verified by live probe** (Task 8). Default: `[text]` only, so unverified models cannot be picked for vision requests.
   - Unmatched ids: excluded from `claude-` only; any other unknown id gets default text caps (future-proofing — Command Code adds models frequently; a vanished model must not take the catalog down).
   - Sort order: non-preview first; `deepseek/deepseek-v4-flash` first overall (matches CC default model, so auto-selection picks the cheapest sane default), rest alphabetical.
6. **Packaging:** dual-use Composer package + WP plugin, structure copied from `openai-ai-provider` (official). Plugin header mirrors official but `Requires at least: 7.0` (connectors API needs 7.0). GPL-2.0-or-later. Text domain `ai-provider-for-commandcode`.
7. **Logo:** use Command Code's logo (source: their docs assets `commandcode-logo.svg`) — **license check is an open question** (Task 0 / see Risks); fallback: neutral mark.
8. **Vision-model preference (separate from the text default):** the catalog sort pins `deepseek/deepseek-v4-flash` first for text tasks, and additionally carries a curated `preferred vision order` (const, probe-informed) so the first *vision-capable* model in that order becomes the automatic default for image-description requests — text-only models drop out of the vision candidate pool via modality filtering, so one global sort can bias text defaults and vision defaults independently. **Default vision model: `MiniMaxAI/MiniMax-M3`** (William's pick; live-verified vision on the chat wire, currently on a 2×-credit deal), followed by `google/gemini-3.8-flash` → `deepseek/deepseek-v4-flash-vision-exp` → `gpt-5.4` (remaining candidates re-probed in Task 8). Consumers wanting a hard per-request vision choice use `using_model_preference()` with vision models; the README ships a copy-paste vision recipe (image → alt text / description).

# Part D — Repo/file layout (mirrors official package)

```
ai-provider-for-commandcode/
├── plugin.php                      # header + autoload + register_provider() on init:5
├── readme.txt                      # WP.org-style readme
├── README.md                       # GitHub readme (setup, key config, model list)
├── LICENSE                         # GPL-2.0-or-later
├── composer.json                   # dual-use; require php>=7.4; require-dev wordpress/php-ai-client ^1.3.1
├── phpcs.xml.dist / phpstan.neon.dist / .gitattributes / .distignore
├── src/
│   ├── autoload.php                # simple classmap (PSR-4 style, like official)
│   ├── Provider/CommandCodeProvider.php
│   ├── Metadata/CommandCodeModelMetadataDirectory.php
│   └── Models/CommandCodeTextGenerationModel.php
├── assets/images/commandcode.svg
└── tests/
    ├── bootstrap.php
    ├── fixtures/models-response.json # real GET /models capture (67 models)
    └── unit/  (+ phpunit.xml.dist at root)
```

Reference files to copy structurally from `/opt/data/wp-ai/openai-ai-provider/`: `plugin.php`, `src/autoload.php`, `composer.json`, `phpcs.xml.dist`, `phpstan.neon.dist`, `.gitattributes`, `.distignore`, `tests/bootstrap.php`, GitHub CI workflow shape (`.github/workflows/ci.yml`: phpcs + phpstan + phpunit matrix).

# Part E — Implementation tasks (bite-sized, TDD where it pays)

> Do **not** copy the OpenAI *model* classes — they implement the Responses API wire (`/v1/responses`) which Command Code does **not** offer. Use the SDK's `AbstractOpenAiCompatible*` base instead. Only copy: plugin.php pattern, provider skeleton, autoload, packaging, tests layout.

### Task 0 — Repo + logo licensing
- Create `pattonwebz/ai-provider-for-commandcode` (public, GPL-2.0-or-later) via `gh`.
- Resolve Command Code logo usage (check commandcode.ai licensing/trademark page; else use a placeholder neutral mark). Record decision in README.

### Task 1 — Package skeleton (mirror official)
- Copy structure from `openai-ai-provider`: plugin.php header (renamed), composer.json (package `pattonwebz/ai-provider-for-commandcode`, require-dev `wordpress/php-ai-client:^1.3.1`), phpcs/phpstan configs, LICENSE, readme.txt, .distignore, .gitattributes, src/autoload.php.
- `composer install` and run `composer phpcs` — expect green or baseline-fixed.

### Task 2 — Provider metadata + registration (TDD)
- `src/Provider/CommandCodeProvider.php`: `baseUrl()` → `https://api.commandcode.ai/provider/v1`; `createProviderMetadata()` → `ProviderMetadata( 'commandcode', 'Command Code', ProviderTypeEnum::cloud(), 'https://commandcode.ai/studio', RequestAuthenticationMethod::apiKey(), __(…description…), <logo path> )` with `AiClient::VERSION` guards copied from official (`1.2.0` desc, `1.3.0` logo).
- `createProviderAvailability()` → `new ListModelsApiBasedProviderAvailability( static::modelMetadataDirectory() )`.
- `plugin.php` `register_provider()` on `init` priority 5 (official pattern verbatim, names swapped).
- Test: metadata id/name/auth; registration is idempotent; no fatal when `AiClient` absent.

### Task 3 — Text generation model (thin)
- `src/Models/CommandCodeTextGenerationModel.php` extends `AbstractOpenAiCompatibleTextGenerationModel`; only `createRequest()` implemented → `new Request( $method, CommandCodeProvider::url( $path ), $headers, $data )` (official directory's pattern).
- Test: request targets `…/provider/v1/chat/completions` with auth attached (mock transporter).

### Task 4 — Model metadata directory: fetch + parse (TDD)
- `src/Metadata/CommandCodeModelMetadataDirectory.php` extends `AbstractOpenAiCompatibleModelMetadataDirectory`; `createRequest()` as above.
- `parseResponseToModelMetadataList()`: read `data[]`; use `name` (fallback `id`) for display; return `ModelMetadata( id, name, capabilities, options )` per classifier (Task 5); tolerate/skip malformed entries.
- Test with `tests/fixtures/models-response.json` (copy of `/tmp/cc_models.json`): all 67 models parsed; 8 `claude-*` excluded; display names populated.

### Task 5 — Classifier (the real logic)
- Central static classifier in the directory class: `isAnthropicWireId( string ): bool` → `/^claude-/`; capability/option table keyed by family regexes; `supportsSamplingOptions( string $id )`, `supportsImageInput( string $id )` returning per-family booleans (constants, versioned, documented — mirrors official's pattern of verified-only families).
- Default tables per Part C §5. Classifier also exposes `isVisionCapable( string $id ): bool` (image-input table, fed by Task 8 probes) and `supportsSamplingOptions()`. Tests: every fixture id classified into exactly one bucket; exclusion list matches fixture's 8 claude ids; a synthetic future id (e.g. `acme/new-model-x`) defaults to basic text caps (no vision).

### Task 6 — Sorting + auto-selection ordering
- `modelSortCallback()`: non-preview first; `deepseek/deepseek-v4-flash` pinned first (text default); then `preferred vision order` (Part C §8) interleaved so the chosen vision default precedes other vision models; then alphabetical by vendor group then id. Test on fixture: assert first model, first *vision-capable* model, and stability.

### Task 7 — Full unit suite green
- `composer test` (phpunit), `composer lint` (phpcs + phpstan) all green. Wire a GitHub Actions CI (mirror official `ci.yml`).

### Task 8 — Live verification probes (against real API, uses `COMMANDCODE_API_KEY`)
Small script (PHP or curl) probing, per candidate family on chat/completions:
- `temperature` acceptance: deepseek, Qwen, gemini, kimi, GLM, MiniMax, grok, gpt-5.4, gpt-5.5 → record 400/200; feed result back into Task 5 sampling table.
- Image input acceptance (tiny 1×1 PNG data URI) — **already done 2026-09-08:** `MiniMaxAI/MiniMax-M3` ✅ vision (described image); `MiniMaxAI/MiniMax-M2.7` ❌ (upstream 400 — no vision). Remaining to probe with a real token budget (≥256): `deepseek/deepseek-v4-flash-vision-exp` (accepted image, empty at 32), `google/gemini-3.8-flash`, `Qwen/Qwen3.8-27B`, `gpt-5.4`, `moonshotai/Kimi-K2.6`, `meta/muse-spark-1.3`, `xai/grok-4.6` → feed results into the image-input table (`isVisionCapable`).
- `max_tokens` behavior per family; confirm `/models` still 200 (availability check path).
- Integration test (opt-in, env-key-gated, mirrors SDK's `tests/integration` convention) asserting one real `generateTextResult()` through the SDK classes.

### Task 9 — End-to-end in a real WP site (William's machine)
- wp-env / local WP 7.0 site; drop plugin in `wp-content/plugins/`; activate.
- Settings → Connectors shows "Command Code" card; paste key OR set `COMMANDCODE_API_KEY`; card shows Connected (availability check hits `/models`).
- Consumer check: Tools/AI example plugin (wpshout pattern) calling `wp_ai_client_prompt( '…' )->generate_text()`; then `->using_model_preference( 'deepseek/deepseek-v4-flash', 'gpt-5.5' )` and `->as_output_schema( … )`; confirm thought-channel/reasoning models don't crash parsing (deepseek `reasoning_content` covered by base class).
- Regression: run the same site with the official OpenAI provider also installed — confirm both cards coexist and capability routing picks per request type.

### Task 10 — Docs + release polish
- README (setup, env vs DB key, model list + classification notes, ZDR header pointer via `using_request_options`, and a **vision recipe**: `wp_ai_client_prompt()->with_file( $image )->using_model_preference( …vision models… )->generate_text()` for alt-text/descriptions — with the explicit note that vision = describe (image-in, text-out), not image generation), readme.txt, FAQ. Tag v0.1.0 (pre-1.0 until the WordPress AI team's plugin naming/structure settles — they renamed things between 6.9→7.0).

# Part F — Validation summary

- **Unit:** classification of the real 67-model fixture; request/URL building; metadata; registration guard. (No WP load needed for most — SDK is WP-agnostic; WP-dependent bits are plugin.php guard + metadata `__()` which has a non-WP fallback, as in official.)
- **Static:** phpcs (WP/PER standard from official configs), phpstan level from official neon.
- **Live:** Task 8 probes + opt-in integration test; Task 9 wp-env E2E on William's machine (no PHP on this box — see Risks).
- **API contract sanity already performed:** `/models` 200 with 67 entries; chat completion OK on deepseek/gemini/qwen; claude → 400 → exclusion rule proven; gpt-5.4 param passthrough proven (min max_tokens 16 upstream).

# Part G — Risks, tradeoffs, open questions

- **No PHP on this box** (user `hermes`, Debian arm64) → local unit-test execution requires installing PHP 8.x + composer (best effort via apt, may need sudo) or deferring test runs to William/CI. Live API probes (Task 8) are shell/curl-based and already proven feasible here.
- **Upstream variance:** Command Code proxies to many upstreams; option acceptance (temperature on reasoning models, image input, min max_tokens) varies per model and can change without notice. Mitigation: conservative advertisement, versioned classifier tables, documented probe results, integration test.
- **Catalog churn:** CC adds/removes models frequently (fixture shows dated snapshots + `:free`/`:contributor` variants). Live fetch + default-include-for-unknown + exclusion-only-for-claude keeps the connector working without constant releases. Conversely, classification accuracy for brand-new vendors is unknown until probed — README should say image/temperature support may lag new models.
- **Logo/trademark:** embedding Command Code's logo needs license clearance; fallback exists.
- **SDK version floor:** core bundles SDK 1.3.1; code must not use ≥1.4.0 APIs (embedding interfaces etc.). Guard with `interface_exists()` where tempted.
- **DB-stored keys unencrypted** — core limitation (trac #64789); document env-var as the production recommendation (works automatically with our derived name).
- **WordPress AI naming still settling** (AI Experiments → "WordPress AI"; provider plugin naming conventions); low risk since we mirror the established official plugin shape.
- **Open question (William):** publish on WordPress.org eventually, or GitHub-only? (Official providers are on .org; community ones like Ollama/Kimi vary.)
- **Open question:** `claude-*` via `/messages` in v2 — option (b) (wire-switching model class inside the same provider) preferred over a second provider/connector, so one key config covers everything.

---

*Research artifacts kept for implementation:* `/tmp/cc_models.json` (live model list fixture), `/opt/data/cache/web/*.md` (Command Code docs + WP blog extracts), local clones listed at top.

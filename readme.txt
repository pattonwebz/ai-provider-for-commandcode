=== AI Provider for Command Code ===
Contributors:      pattonwebz
Tags:              ai, command-code, commandcode, connector, artificial-intelligence
Requires at least: 7.0
Tested up to:      7.0
Stable tag:        0.1.1
Requires PHP:      7.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

AI Provider for Command Code for the PHP AI Client SDK.

== Description ==

This plugin provides Command Code integration for the WordPress AI Client. It
enables WordPress sites to use Command Code's API (DeepSeek, GPT, Gemini, and
other models) for AI-powered text generation through the native WordPress 7.0
Connectors system.

After activation, a "Command Code" connector appears under Settings → Connectors.
Add your API key (from Command Code Studio) there, or define the
`COMMANDCODE_API_KEY` constant / environment variable.

**Features:**

* Text generation with Command Code models (DeepSeek, GPT, Gemini, Qwen, Kimi, MiniMax, and more)
* Vision support: image-in / text-out description and analysis with verified vision models (e.g. MiniMax-M3)
* Live model discovery from the Command Code models endpoint
* Automatic connector registration with the WordPress 7.0 Connectors API
* Works with any plugin that uses `wp_ai_client_prompt()`

**Vision note:** vision models in this provider *describe* images (image in,
text out) — Command Code's API does not offer image generation, and this
provider never advertises an image-generation capability.

== Installation ==

1. Upload the `ai-provider-for-commandcode` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to Settings → Connectors and enter your Command Code API key (or define
   `COMMANDCODE_API_KEY` in `wp-config.php`).
4. Any plugin using the WordPress AI Client can now generate text.

== Frequently Asked Questions ==

= Which models are available? =

The catalog is fetched live from the Command Code `/models` endpoint. Claude
models are excluded in v1 because they require Command Code's Anthropic-compatible
endpoint, which is not yet implemented.

= How do I pick a model? =

Use `using_model_preference()` on the AI Client prompt builder, e.g.
`wp_ai_client_prompt( '...' )->using_model_preference( 'deepseek/deepseek-v4-flash' )`.

= How do I describe an image? =

Add the image to the prompt with `with_file()`. Vision requests automatically
resolve to the default vision model (MiniMaxAI/MiniMax-M3) or to whichever
verified vision model you prefer via `using_model_preference()`.

== Changelog ==

= 0.1.1 =

* New `ai_provider_for_commandcode_models` filter: restrict the model catalog to a preferred allowlist (or remove individual models).

= 0.1.0 =

* Initial release: text generation with Command Code models over the OpenAI-compatible chat completions endpoint.
* Live model catalog with probe-verified capability classification.
* Vision (image input) support for verified models.
* Automatic WordPress 7.0 connector registration.

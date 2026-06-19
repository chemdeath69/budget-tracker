# Service · Anthropic (Claude) — OCR + AI assistant   ⬜ OPTIONAL · 💲 Paid (pay-as-you-go)

[← Back to the Installation Guide](../../INSTRUCTIONS.md) · [Service index](../../INSTRUCTIONS.md#third-party-services)

One key unlocks **two** features:

1. **401(k) statement photo import** — snap a photo of a paper retirement statement and Claude's
   vision reads it into the statement form (`retirement_statement.php` → "Import from photo").
2. **AI assistant** (`assistant.php`) — a natural-language chat that answers questions about your
   finances by calling the app's own read-only query helpers (it never touches the database directly).

**config keys:** `anthropic.api_key`, `anthropic.model`, `anthropic.assistant_model` · **Disable:**
leave `api_key` `''` (hides **both** features)

> 💲 **This is the only feed billed per use.** It uses **Anthropic API prepaid credits** — *not*
> covered by a Claude.ai or Claude Code subscription. Costs are small (a statement photo or a chat
> turn is cents), but you must load credits.

---

## Get a key + credits

### 1. Sign up for the Claude Console

Go to **<https://console.anthropic.com>** — it now redirects to **platform.claude.com**. Sign up /
sign in (Continue with Google, or email). ⚠️ This is the **developer API console**, a *separate*
account from Claude.ai — even if you use Claude.ai, the API console needs its own sign-up + credits.

![Claude Console / platform.claude.com sign-in](../img/anthropic-01-signin.png)

A brand-new account completes a short **onboarding** (your name + accept terms) before the keys page
unlocks.

### 2. Add credits

Go to **Settings → Billing**, add a **payment method**, and **purchase credits** (e.g. $5 — that lasts
a long time for personal use; the loaded balance shows as **Credits** in the sidebar).

### 3. Create the API key

Go to **Settings → API keys → Create key**, give it a **Name** (e.g. `example-instance`), and click **Add**.
The full key (`sk-ant-…`) is shown **once** in a *"Save your API key"* dialog — **copy it now**, you
can't view it again.

![Create an API key — shown once](../img/anthropic-02-api-key.png)

## Add it to `config.php`

```php
'anthropic' => [
    'api_key'         => 'sk-ant-...',          // empty '' = both features disabled
    'model'           => 'claude-sonnet-4-6',   // vision model for statement OCR (accurate on dense tables)
    'assistant_model' => '',                    // optional override for chat; empty = use `model`
],
```

- **`model`** — used for the statement-photo OCR. `claude-sonnet-4-6` is recommended (most accurate on
  dense funding sub-tables). A cheaper model can misread digits.
- **`assistant_model`** — optionally point the chat assistant at a different model; empty falls back
  to `model`.

Re-upload `config.php`. The "Import from photo" button appears on the retirement-statement page and
the **AI assistant** page becomes available. With an empty `api_key`, both are hidden/disabled (the
assistant endpoint returns 503, the importer is hidden).

## Verify

Open the **Assistant** page and ask a question — it should answer from your data (it's read-only). A
working reply confirms the key + credits are live:

![Assistant page answering a question](../img/anthropic-03-assistant-verified.png)

## Notes

- ⚠️ **Empty string disables; a non-empty placeholder is treated as a real key.** Keep it `''` until
  you have a real `sk-ant-…` key with credits loaded, or the OCR/assistant calls will error.
- Each statement-photo import is a single vision call (~a few cents). Each assistant question is a
  short tool-use conversation (also cents). Set a monthly spend limit in the Anthropic console if you
  want a hard ceiling.

→ Back to the [main guide](../../INSTRUCTIONS.md#third-party-services)

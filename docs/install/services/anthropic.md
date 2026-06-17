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

1. Go to **<https://console.anthropic.com>** and sign up / sign in.
2. **API Keys** → **Create Key**. Copy it (`sk-ant-…`).
3. **Billing** → add a payment method and **load credits** (e.g. $5 to start — that lasts a long time
   for personal use).

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

## Notes

- ⚠️ **Empty string disables; a non-empty placeholder is treated as a real key.** Keep it `''` until
  you have a real `sk-ant-…` key with credits loaded, or the OCR/assistant calls will error.
- Each statement-photo import is a single vision call (~a few cents). Each assistant question is a
  short tool-use conversation (also cents). Set a monthly spend limit in the Anthropic console if you
  want a hard ceiling.

→ Back to the [main guide](../../INSTRUCTIONS.md#third-party-services)

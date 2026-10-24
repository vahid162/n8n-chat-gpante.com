# Shared discovery and reliable conversation

Feature017 targets WordPress0.10.0, Main1.7.0 and Admin1.3.0. The [verification record](../specs/017-reliable-site-assistant/verification.md) distinguishes local checks from the actual rollout.

## Request flow

The visitor's signed cookie identifies a conversation. WordPress validates the fixed operation and forwards only trusted session/client identities. PostgreSQL admits the request under the existing limits. A repeated successful sendId returns the same visible answer before model reservation; pending, failed, cleared and expired sends have distinct outcomes.

The model chooses the source appropriate to the request: public site discovery for learning/pages, articles for an explicit article request, structured WooCommerce for products, FAQ for practical support and approved guides for contractual text. Site discovery and standard WordPress main search share the native public index. A source is chosen by intent; the system does not read every source in a fixed sequence.

Each model call reserves and settles usage before the next action. At most three tools and four model calls are available. Current-turn evidence and each linked recommendation bound citations, prices and supported attributes. Unsupported or ambiguous claims enter a correction round inside those same limits, or finish with a fixed error. Syntactic validation does not prove that every natural-language claim follows from a source.

The answer is stored once, returned through the server bridge and rendered as Persian bubbles and links. User history pages six complete turns independently of the model's six-turn context. Cached older pages are dropped when clear markers or policy revisions change, so missed intermediate retention changes cannot resurrect text. Recent activity renews the same cookie identity; browser navigation does not create a durable outbound queue.

## Index coverage and freshness

One plugin-owned WordPress table contains normalized public static title/body text and document state. It excludes mutable prices and all customer/order data. Active Elementor mode supplies builder text; inactive retained metadata does not override the ordinary editor. Lifecycle hooks update documents; leased batches bootstrap and reconcile all eligible IDs. The index is not a vector or semantic search service.

Pages, posts and eligible products are automatic. Native pagination and current eligibility checks remain in force. Product results re-read current WooCommerce facts. A broken static document is isolated and coverage becomes incomplete; an empty incomplete response never proves absence. Spelling suggestions are bounded, explicit and unapplied. Material, thickness, format, version and free/paid constraints are never silently relaxed. A verified one-piece cutting attribute is not available and is not inferred from an image.

Only ordinary front-end main WordPress search is integrated; unrelated theme-specific AJAX remains under the theme's control. AI unavailability offers a trusted native search link without another model call. Inspect readiness/invalid IDs under Chat Pante → Diagnostics after upgrades. Background batches need working WordPress cron; a delayed bootstrap remains visibly incomplete.

## Diagnostics and feedback

Diagnostics retain source/tool, a sanitized bounded query, selected filters, result IDs/ranks and fixed rejection codes. JavaScript and SQL independently discard unknown fields and redact recognizable identifiers/tokens. No source body, URL, raw provider payload or transcript is copied into this store. Retention is seven days with a global2,000-record cap, independent of the30-day administrator archive and text-free financial ledger.

Visitors rate only their own visible successful answers. Only the protected administrator API can mark a known turn synthetic; unclassified historical traffic is not assumed human. Summary counts are operational signals, not a measured accuracy score. Existing web-server access logs remain subject to the server's separate logging policy.

## Upgrade and rollback

Keep protected settings/plugin/workflow rollback copies outside the web root and public Git. Drain Main, apply004 and005 once as the application owner, then publish matching Main/Admin and install the verified official plugin package. Preserve credentials, settings, policy, prices and unrelated workflows. Reconcile the index and verify the installed files and active definitions. Existing v3 functions remain compatible; rolling back workflow JSON does not undo migrations or billed calls. Never restore the old destructive reset behavior or refund unknown provider usage.

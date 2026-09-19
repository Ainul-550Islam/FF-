# API v2 Strategy

## Current v1 Stability

`/api/v1` is stable and additive-only:
- 258 routes, bearer auth Sanctum, abilities, idempotency, throttling
- Controllers thin, services reusable
- No breaking changes in v1

## v2 Goals

- Improved pagination (cursor)
- Standardized error envelope v2
- Versioned payloads with explicit version field
- New tournament formats (swiss, group_stage, league, ffa, multi_stage, hybrid)
- New scoring (custom, tiebreaker)
- New payment providers (nagad, rocket)
- New payout gateways (bkash, bank)
- Realtime Reverb transport
- Enhanced fraud signals

## How to Add v2 Without Breaking v1

1. Create `routes/api_v2.php`:
```php
Route::prefix('v2')->name('api.v2.')->group(function () {
  // reuse same controllers or new V2 controllers that delegate to same services
});
```
2. Register in `bootstrap/app.php` `withRouting(api: ..., then: require api_v2)`
3. Reuse existing services: TournamentFormatManager, ScoringManager, PaymentProviderManager, etc
4. Only HTTP translation differs: request validation, response resources
5. Keep v1 controllers untouched

## Example v2 Controller Delegation

```php
class TournamentControllerV2 extends Controller {
  public function index(TournamentFormatManager $formats) {
    $tournaments = Tournament::paginate(20);
    return new TournamentCollectionV2($tournaments);
  }
}
```

## Resources

- Use `Illuminate\Http\Resources\Json\JsonResource` for v2
- Keep v1 returning arrays for backward compat
- v2 resources add version field: `"api_version": "v2"`

## Feature Flag

`config/features.php` `api_v2` env `FEATURE_API_V2` false default.
Middleware `feature:api_v2` can gate v2 routes until ready.

## No Fake v2

- Do NOT create empty v2 controllers that return `ok:true`
- Do NOT duplicate business logic
- Do reuse services, managers, models
- v2 is additive layer over same domain

## Migration Path

- Clients opt-in via `/api/v2` header or prefix
- v1 remains indefinitely
- Sunset policy: 12 months notice, changelog, migration guide

## Observability

- Metrics tagged `api_version=v1|v2`
- Structured logs include `api_version`
- Tracing via X-Request-ID

## Security

- Same auth: bearer Sanctum
- Same abilities, rate limits
- Webhook HMAC same

## OpenAPI

- v2 spec separate file `docs/openapi_v2.yaml` when v2 launches
- v1 spec `docs/openapi.yaml` remains source of truth for current public API

## Mobile

- Flutter modular: `lib/features/tournaments/data/api/v1` and `v2`
- Repository interface same, implementation switches version via config

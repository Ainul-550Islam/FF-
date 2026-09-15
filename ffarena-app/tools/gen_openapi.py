#!/usr/bin/env python3
"""
Phase 15 — OpenAPI 3.0 generator + validation gate.

Builds storage/api-docs/openapi.json from an explicit path table and shared
component schemas, then cross-checks that every documented path matches a
route registered by the application (`php artisan route:list --json`). A path
that is documented but not routed — or a routed public business endpoint that
is missing from the spec — is a hard failure.

Usage:
    python3 tools/gen_openapi.py            # (re)generate + validate
    python3 tools/gen_openapi.py --validate # validate the existing file only
"""

import json
import re
import subprocess
import sys

ROOT = "/home/user/ffarena-app"
OUT = f"{ROOT}/storage/api-docs/openapi.json"

BEARER = [{"bearerAuth": []}]
NONE = []

# ---------------------------------------------------------------------------
# Path table: (method, path, summary, security, scopes, request/response hints)
# Path params are written as {name}. Scopes string is informational.
# ---------------------------------------------------------------------------
PATHS = [
    # --- Authentication -----------------------------------------------------
    ("post", "/api/v1/auth/register", "Register an account", NONE, None,
     {"register": True, "rate": "api_register (3/hour/IP)"}),
    ("post", "/api/v1/auth/login", "Login with email + password", NONE, None,
     {"login": True, "rate": "api_login (5/min/identifier)"}),
    ("post", "/api/v1/auth/google", "Login with a Google id_token", NONE, None,
     {"google": True, "rate": "api_login (5/min/identifier)"}),
    ("post", "/api/v1/auth/otp/request", "Request a phone OTP", NONE, None,
     {"otp": True, "rate": "api_otp_request (1/min/phone)"}),
    ("post", "/api/v1/auth/otp/verify", "Verify a phone OTP and login", NONE, None,
     {"otp": True, "rate": "api_otp_verify (5/5min/phone)"}),

    # --- Public discovery --------------------------------------------------
    ("get", "/api/v1/app/meta", "App metadata & compatibility", NONE, None,
     {"rate": "api_anon (60/min/IP)"}),
    ("get", "/api/v1/tournaments", "List public tournaments", NONE, None,
     {"rate": "api_anon (60/min/IP)"}),
    ("get", "/api/v1/tournaments/{tournament}", "Show a tournament", NONE, None, {}),
    ("get", "/api/v1/tournaments/{tournament}/matches", "List a tournament's matches", NONE, None, {}),
    ("get", "/api/v1/tournaments/{tournament}/leaderboard", "Tournament leaderboard", NONE, None, {}),
    ("get", "/api/v1/tournaments/{tournament}/bracket", "Tournament bracket", NONE, None, {}),
    ("get", "/api/v1/matches/{match}", "Show a match", NONE, None, {}),
    ("get", "/api/v1/players/{user}", "Public player profile", NONE, None, {}),
    ("get", "/api/v1/players/{user}/ranking", "Player's rankings", NONE, None, {}),
    ("get", "/api/v1/leaderboards", "Ranked tournaments", NONE, None, {}),
    ("get", "/api/v1/leaderboards/{tournament}", "Tournament standings", NONE, None, {}),

    # --- Me / profile / security ------------------------------------------
    ("get", "/api/v1/me", "Current user", BEARER, "profile:read", {}),
    ("get", "/api/v1/me/security", "Sign-in methods & account status", BEARER, "profile:read", {}),
    ("get", "/api/v1/me/sessions", "Active sessions", BEARER, "profile:read", {}),
    ("get", "/api/v1/me/tokens", "Personal access tokens", BEARER, "profile:read", {}),
    ("get", "/api/v1/me/clients", "API clients", BEARER, "profile:read", {}),
    ("put", "/api/v1/me/profile", "Update profile", BEARER, "profile:write", {}),
    ("patch", "/api/v1/me/profile", "Update profile (partial)", BEARER, "profile:write", {}),
    ("delete", "/api/v1/me/sessions/{session}", "Revoke a session", BEARER, "profile:write", {}),
    ("post", "/api/v1/me/sessions/revoke-others", "Revoke other sessions", BEARER, "profile:write", {}),
    ("post", "/api/v1/me/sessions/revoke-all", "Revoke all sessions", BEARER, "profile:write", {}),
    ("post", "/api/v1/me/tokens", "Create a personal access token", BEARER, "profile:write",
     {"rate": "api_token_issue (5/min/user)"}),
    ("post", "/api/v1/me/clients", "Create an API client", BEARER, "profile:write",
     {"rate": "api_token_issue (5/min/user)"}),
    ("delete", "/api/v1/me/clients/{client}", "Revoke an API client", BEARER, "profile:write", {}),
    ("delete", "/api/v1/me/tokens/{tokenId}", "Revoke a token", BEARER, "profile:write", {}),

    # --- Notifications + realtime -----------------------------------------
    ("get", "/api/v1/me/notifications", "List notifications", BEARER, "notifications:read", {}),
    ("get", "/api/v1/me/notifications/unread-count", "Unread notification count", BEARER, "notifications:read", {}),
    ("post", "/api/v1/me/notifications/{notification}/read", "Mark a notification read", BEARER, "notifications:write", {}),
    ("post", "/api/v1/me/notifications/read-all", "Mark all notifications read", BEARER, "notifications:write", {}),
    ("get", "/api/v1/me/live", "Own realtime cursor feed", BEARER, "notifications:read", {}),
    ("get", "/api/v1/tournaments/{tournament}/live", "Tournament realtime feed", NONE, None,
     {"rate": "api_anon (60/min/IP)"}),

    # --- Mobile push devices (Phase 18) ------------------------------------
    ("get", "/api/v1/me/devices", "Registered mobile devices", BEARER, "notifications:read", {}),
    ("post", "/api/v1/me/devices", "Register a mobile device", BEARER, "notifications:write", {}),
    ("delete", "/api/v1/me/devices/{device}", "Remove a mobile device", BEARER, "notifications:write", {}),

    # --- Push notification preferences (Phase 19) --------------------------
    ("get", "/api/v1/me/notification-preferences", "Push notification preferences", BEARER, "notifications:read", {}),
    ("patch", "/api/v1/me/notification-preferences", "Update push notification preferences", BEARER, "notifications:write", {}),

    # --- Teams / roster ----------------------------------------------------
    ("get", "/api/v1/me/teams", "Own teams", BEARER, "teams:read", {}),
    ("get", "/api/v1/teams/{team}", "Show a team", BEARER, "teams:read", {}),
    ("patch", "/api/v1/teams/{team}", "Update a team", BEARER, "teams:write", {}),
    ("post", "/api/v1/teams/{team}/withdraw", "Withdraw a team", BEARER, "teams:write", {}),
    ("get", "/api/v1/teams/{team}/roster", "List roster", BEARER, "roster:read", {}),
    ("post", "/api/v1/teams/{team}/roster", "Add a roster member", BEARER, "roster:write", {}),
    ("delete", "/api/v1/teams/{team}/roster/{member}", "Remove a roster member", BEARER, "roster:write", {}),

    # --- Registration / check-in / waitlist -------------------------------
    ("post", "/api/v1/tournaments/{tournament}/registrations", "Register a team", BEARER, "tournaments:register",
     {"idempotency": True}),
    ("post", "/api/v1/tournaments/{tournament}/check-in", "Check a team in", BEARER, "tournaments:register", {}),
    ("get", "/api/v1/tournaments/{tournament}/waitlist", "Waitlist positions", BEARER, "tournaments:read", {}),

    # --- Scores ------------------------------------------------------------
    ("post", "/api/v1/matches/{match}/scores", "Submit a score", BEARER, "scores:submit",
     {"idempotency": True, "rate": "api_score (10/min/user)"}),

    # --- Payments / wallet / payouts --------------------------------------
    ("get", "/api/v1/payments/methods", "Payment providers & saved methods", BEARER, "wallet:read", {}),
    ("post", "/api/v1/payments", "Create a payment", BEARER, "payments:create",
     {"idempotency": True, "rate": "api_payment (5/min/user)"}),
    ("get", "/api/v1/payments/{payment}", "Show a payment", BEARER, "payments:read", {}),
    ("get", "/api/v1/me/wallet", "Wallet summary", BEARER, "wallet:read", {}),
    ("get", "/api/v1/me/wallet/ledger", "Wallet ledger", BEARER, "wallet:read", {}),
    ("get", "/api/v1/me/payouts", "Own payouts", BEARER, "payouts:read", {}),

    # --- Support / disputes ------------------------------------------------
    ("get", "/api/v1/me/support", "Own support tickets", BEARER, "support:read", {}),
    ("post", "/api/v1/me/support", "Create a support ticket", BEARER, "support:write",
     {"idempotency": True, "rate": "api_support (10/min/user)"}),
    ("get", "/api/v1/me/support/{ticket}", "Show a ticket", BEARER, "support:read", {}),
    ("get", "/api/v1/me/support/{ticket}/messages", "Ticket messages", BEARER, "support:read", {}),
    ("post", "/api/v1/me/support/{ticket}/messages", "Reply to a ticket", BEARER, "support:write",
     {"rate": "api_support (10/min/user)"}),
    ("get", "/api/v1/me/disputes", "Own disputes", BEARER, "disputes:read", {}),
    ("get", "/api/v1/disputes/{dispute}", "Show a dispute", BEARER, "disputes:read", {}),

    # --- Admin webhooks (outbound subscriptions) --------------------------
    ("get", "/api/v1/admin/webhooks/endpoints", "List webhook endpoints", BEARER, "admin", {}),
    ("post", "/api/v1/admin/webhooks/endpoints", "Create a webhook endpoint", BEARER, "admin", {}),
    ("get", "/api/v1/admin/webhooks/endpoints/{endpoint}", "Show an endpoint", BEARER, "admin", {}),
    ("post", "/api/v1/admin/webhooks/endpoints/{endpoint}/rotate-secret", "Rotate endpoint secret", BEARER, "admin", {}),
    ("post", "/api/v1/admin/webhooks/endpoints/{endpoint}/toggle", "Enable/disable endpoint", BEARER, "admin", {}),
    ("get", "/api/v1/admin/webhooks/endpoints/{endpoint}/deliveries", "Endpoint deliveries", BEARER, "admin", {}),
    ("get", "/api/v1/admin/webhooks/events", "Webhook event vocabulary", BEARER, "admin", {}),

    # --- Inbound provider webhooks ----------------------------------------
    ("post", "/api/v1/webhooks/inbound/{provider}", "Inbound provider webhook", NONE, None,
     {"inbound_webhook": True, "rate": "api_webhook (60/min/IP)"}),
]


def build_paths():
    """Build the OpenAPI `paths` object."""
    paths = {}
    for method, path, summary, security, scopes, hints in PATHS:
        if path not in paths:
            paths[path] = {}
        op = {
            "summary": summary,
            "operationId": f"{method}_{re.sub(r'[^a-zA-Z0-9]', '_', path.strip('/'))}",
            "tags": [tag_for(path)],
            "responses": responses_for(method, hints),
        }
        if security:
            op["security"] = security
        else:
            op["security"] = []
        params = path_params(path)
        if params:
            op["parameters"] = params
        body = body_for(method, path, hints)
        if body:
            op["requestBody"] = body
        desc_bits = []
        if scopes:
            desc_bits.append(f"**Required scope:** `{scopes}`.")
        if hints.get("idempotency"):
            desc_bits.append(
                "Supports the `Idempotency-Key` header: a replay within the TTL "
                "returns the stored response; reusing a key with a different "
                "body returns 409."
            )
        if hints.get("rate"):
            desc_bits.append(f"**Rate limit:** `{hints['rate']}`.")
        if hints.get("inbound_webhook"):
            desc_bits.append(
                "Authenticated by HMAC-SHA256 over the raw body "
                "(`X-Signature`), a fresh `X-Timestamp`, and an event-id "
                "idempotency check. Content-Type must be `application/json`."
            )
        if desc_bits:
            op["description"] = "\n\n".join(desc_bits)
        paths[path][method] = op
    return paths


def tag_for(path):
    if "/auth/" in path:
        return "Auth"
    if path.startswith("/api/v1/tournaments"):
        return "Tournaments"
    if path.startswith("/api/v1/matches"):
        return "Matches"
    if path.startswith("/api/v1/teams") or path == "/api/v1/me/teams":
        return "Teams"
    if path.startswith("/api/v1/players") or path.startswith("/api/v1/leaderboards"):
        return "Players & Leaderboards"
    if "/notifications" in path or path.endswith("/live"):
        return "Notifications & Realtime"
    if path.startswith("/api/v1/payments") or "/wallet" in path or "/payouts" in path:
        return "Payments & Wallet"
    if "/support" in path or "/disputes" in path:
        return "Support & Disputes"
    if "/admin/webhooks" in path:
        return "Admin Webhooks"
    if "/webhooks/inbound" in path:
        return "Inbound Webhooks"
    if path.startswith("/api/v1/me"):
        return "Me"
    return "General"


def path_params(path):
    names = re.findall(r"\{([a-zA-Z_]+)\}", path)
    return [{
        "name": n,
        "in": "path",
        "required": True,
        "schema": {"type": "string"},
        "description": path_param_desc(n),
    } for n in names]


def path_param_desc(name):
    return {
        "tournament": "Tournament slug",
        "match": "Match id",
        "team": "Team id",
        "member": "Roster member id",
        "user": "User id",
        "payment": "Payment id",
        "ticket": "Support ticket id",
        "dispute": "Dispute id",
        "session": "Session id",
        "tokenId": "Token id",
        "client": "API client id",
        "endpoint": "Webhook endpoint id",
        "provider": "Provider id (bkash|nagad|rocket|sslcommerz|card)",
        "device": "Mobile device id",
        "notification": "Notification id",
    }.get(name, name)


def responses_for(method, hints):
    ok = "200"
    if method == "post":
        ok = "201"
    elif method == "delete":
        ok = "204"
    envelope = {"$ref": "#/components/schemas/Envelope"}
    responses = {
        ok: {"description": "Success", "content": {"application/json": {"schema": envelope}}},
        "401": {"$ref": "#/components/responses/Unauthorized"},
        "403": {"$ref": "#/components/responses/Forbidden"},
        "404": {"$ref": "#/components/responses/NotFound"},
        "422": {"$ref": "#/components/responses/ValidationError"},
        "429": {"$ref": "#/components/responses/RateLimited"},
    }
    if method == "delete" and ok == "204":
        responses["204"] = {"description": "No content"}
        responses.pop("200", None)
    return responses


def body_for(method, path, hints):
    if method not in ("post", "put", "patch"):
        return None
    schema = {"type": "object"}
    example = None
    if "auth/register" in path:
        example = {"name": "Alice", "username": "alice", "email": "alice@example.com",
                   "phone": "01712345678", "role": "player",
                   "password": "secret123", "password_confirmation": "secret123"}
    elif "auth/login" in path:
        example = {"email": "alice@example.com", "password": "secret123"}
    elif "auth/google" in path:
        example = {"id_token": "<google id_token>"}
    elif "otp/request" in path:
        example = {"phone": "01712345678", "purpose": "login"}
    elif "otp/verify" in path:
        example = {"phone": "01712345678", "purpose": "login", "code": "123456"}
    elif path.endswith("/me/profile"):
        example = {"name": "Alice", "bio": "Player", "privacy": "public"}
    elif path.endswith("/registrations"):
        example = {"name": "Squad", "captain_name": "Captain", "phone": "01712345678",
                   "game_uid": "UID1234", "members": [{"player_name": "P1", "game_uid": "UID5678"}]}
    elif path.endswith("/check-in"):
        example = {"team_id": 1}
    elif path.endswith("/scores"):
        example = {"team_id": 1, "kills": 5, "placement": 1}
    elif path.endswith("/payments"):
        example = {"team_id": 1, "provider": "bkash"}
    elif path.endswith("/me/devices"):
        example = {"platform": "android", "provider": "fcm", "token": "<device push token>", "device_label": "Pixel 9"}
    elif path.endswith("/me/notification-preferences"):
        example = {"tournament": True, "payment": False}
    elif path.endswith("/me/support"):
        example = {"subject": "Help", "category": "payment", "message": "Details"}
    elif path.endswith("/messages"):
        example = {"body": "Reply text"}
    elif path.endswith("/teams/{team}/roster") or path.endswith("/roster"):
        example = {"player_name": "P1", "game_uid": "UID1234"}
    elif path.endswith("/me/tokens"):
        example = {"name": "mobile", "scopes": ["profile:read"], "expires_in_days": 30}
    elif path.endswith("/me/clients"):
        example = {"name": "My App", "description": "optional", "scopes": ["profile:read"]}
    elif path.endswith("/webhooks/endpoints"):
        example = {"url": "https://example.com/hooks", "description": "optional", "events": ["payment.succeeded"]}
    elif path.endswith("/toggle"):
        example = {"status": "active"}
    return {"required": True, "content": {
        "application/json": {"schema": schema, "example": example} if example else {"schema": schema}}}


def components():
    return {
        "securitySchemes": {
            "bearerAuth": {
                "type": "http",
                "scheme": "bearer",
                "bearerFormat": "personal access token",
                "description": "Personal access token issued by /api/v1/auth/* or /api/v1/me/tokens. "
                               "Session cookies are NOT accepted by the API.",
            }
        },
        "responses": {
            "Unauthorized": {"description": "Missing/invalid token", "content": {
                "application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
            "Forbidden": {"description": "Insufficient scope or authorization", "content": {
                "application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
            "NotFound": {"description": "Resource not found", "content": {
                "application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
            "ValidationError": {"description": "Validation failed", "content": {
                "application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
            "RateLimited": {"description": "Rate limit exceeded", "content": {
                "application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
        },
        "schemas": {
            "Envelope": {"type": "object", "properties": {
                "data": {}, "meta": {"type": "object"}}},
            "ErrorResponse": {"type": "object", "properties": {
                "error": {"$ref": "#/components/schemas/Error"}}},
            "Error": {"type": "object", "required": ["code", "message"], "properties": {
                "code": {"type": "string"}, "message": {"type": "string"},
                "details": {"type": "object", "additionalProperties": {"type": "string"}}}},
            "Tournament": {"type": "object", "properties": {
                "id": {"type": "integer"}, "slug": {"type": "string"}, "name": {"type": "string"},
                "game_mode": {"type": "string", "enum": ["squad", "duo", "solo"]},
                "map": {"type": "string"}, "format": {"type": "string"},
                "status": {"type": "string"}, "entry_fee": {"type": "string"},
                "entry_fee_minor": {"type": "integer"}, "currency": {"type": "string"},
                "prize_pool": {"type": "string"}, "team_slots": {"type": "integer"},
                "team_size": {"type": "integer"}, "starts_at": {"type": "string", "format": "date-time"},
                "check_in_starts_at": {"type": "string", "format": "date-time"},
                "check_in_ends_at": {"type": "string", "format": "date-time"},
                "slots_left": {"type": "integer"}, "is_full": {"type": "boolean"},
                "accepts_registration": {"type": "boolean"},
                "confirmed_teams_count": {"type": "integer"},
                "organizer": {"type": "object"}}},
            "Match": {"type": "object", "properties": {
                "id": {"type": "integer"}, "tournament_id": {"type": "integer"},
                "round": {"type": "integer"}, "match_no": {"type": "integer"},
                "bracket": {"type": "string"}, "status": {"type": "string"},
                "scheduled_at": {"type": "string", "format": "date-time"},
                "completed_at": {"type": "string", "format": "date-time"},
                "room_id": {"type": "string"}, "room_pass": {"type": "string"},
                "team1": {"type": "object"}, "team2": {"type": "object"},
                "winner": {"type": "object"}, "scores": {"type": "array", "items": {"type": "object"}}}},
            "Score": {"type": "object", "properties": {
                "id": {"type": "integer"}, "team_id": {"type": "integer"},
                "kills": {"type": "integer"}, "placement": {"type": "integer"},
                "placement_points": {"type": "integer"}, "kill_points": {"type": "integer"},
                "points": {"type": "integer"}, "status": {"type": "string"}}},
            "Team": {"type": "object", "properties": {
                "id": {"type": "integer"}, "tournament_id": {"type": "integer"},
                "name": {"type": "string"}, "captain_name": {"type": "string"},
                "game_uid": {"type": "string"}, "status": {"type": "string"},
                "waitlist_position": {"type": "integer"}}},
            "UserProfile": {"type": "object", "properties": {
                "id": {"type": "integer"}, "name": {"type": "string"},
                "username": {"type": "string"}, "visible": {"type": "boolean"},
                "privacy": {"type": "string"}}},
            "Notification": {"type": "object", "properties": {
                "id": {"type": "integer"}, "type": {"type": "string"},
                "title": {"type": "string"}, "body": {"type": "string"},
                "read": {"type": "boolean"}}},
            "Payment": {"type": "object", "properties": {
                "id": {"type": "integer"}, "tournament_id": {"type": "integer"},
                "team_id": {"type": "integer"}, "amount": {"type": "string"},
                "amount_minor": {"type": "integer"}, "currency": {"type": "string"},
                "provider": {"type": "string"}, "status": {"type": "string"}}},
            "Wallet": {"type": "object", "properties": {
                "id": {"type": "integer"}, "balance": {"type": "string"},
                "balance_minor": {"type": "integer"}, "currency": {"type": "string"},
                "status": {"type": "string"}}},
            "LedgerEntry": {"type": "object", "properties": {
                "id": {"type": "integer"}, "direction": {"type": "string", "enum": ["credit", "debit"]},
                "amount_minor": {"type": "integer"}, "balance_after_minor": {"type": "integer"},
                "type": {"type": "string"}}},
            "Payout": {"type": "object", "properties": {
                "id": {"type": "integer"}, "tournament_id": {"type": "integer"},
                "rank": {"type": "integer"}, "amount_minor": {"type": "integer"},
                "currency": {"type": "string"}, "status": {"type": "string"}}},
            "SupportTicket": {"type": "object", "properties": {
                "id": {"type": "integer"}, "subject": {"type": "string"},
                "category": {"type": "string"}, "priority": {"type": "string"},
                "status": {"type": "string"}}},
            "SupportMessage": {"type": "object", "properties": {
                "id": {"type": "integer"}, "ticket_id": {"type": "integer"},
                "body": {"type": "string"}}},
            "Dispute": {"type": "object", "properties": {
                "id": {"type": "integer"}, "match_id": {"type": "integer"},
                "category": {"type": "string"}, "status": {"type": "string"},
                "description": {"type": "string"}, "resolution": {"type": "string"}}},
            "Token": {"type": "object", "properties": {
                "id": {"type": "integer"}, "name": {"type": "string"},
                "abilities": {"type": "array", "items": {"type": "string"}},
                "last_used_at": {"type": "string", "format": "date-time"},
                "expires_at": {"type": "string", "format": "date-time"}}},
            "ApiClient": {"type": "object", "properties": {
                "id": {"type": "integer"}, "name": {"type": "string"},
                "description": {"type": "string"}, "status": {"type": "string"}}},
            "WebhookEndpoint": {"type": "object", "properties": {
                "id": {"type": "integer"}, "url": {"type": "string"},
                "status": {"type": "string"}, "events": {"type": "array", "items": {"type": "string"}},
                "consecutive_failures": {"type": "integer"}}},
            "WebhookDelivery": {"type": "object", "properties": {
                "id": {"type": "integer"}, "event": {"type": "string"},
                "delivery_id": {"type": "string"}, "status": {"type": "string"},
                "attempts": {"type": "integer"}}},
            "Me": {"type": "object", "properties": {
                "id": {"type": "integer"}, "name": {"type": "string"},
                "username": {"type": "string"}, "email": {"type": "string"},
                "email_verified": {"type": "boolean"}, "avatar": {"type": "string"},
                "bio": {"type": "string"}, "country": {"type": "string"},
                "region": {"type": "string"}, "role": {"type": "string"},
                "privacy": {"type": "string"}, "joined_at": {"type": "string", "format": "date"}}},
            "AuthSession": {"type": "object", "properties": {
                "token": {"type": "string"},
                "token_expires_at": {"type": "string", "format": "date-time"},
                "user": {"$ref": "#/components/schemas/Me"}}},
            "LiveEvent": {"type": "object", "properties": {
                "id": {"type": "integer"}, "type": {"type": "string"},
                "tournament_id": {"type": "integer"}, "payload": {"type": "object"},
                "created_at": {"type": "string", "format": "date-time"}}},
            "Session": {"type": "object", "properties": {
                "id": {"type": "string"}, "device_label": {"type": "string"},
                "last_activity": {"type": "string", "format": "date-time"},
                "is_current": {"type": "boolean"}}},
            "StandingRow": {"type": "object", "properties": {
                "rank": {"type": "integer"}, "team_id": {"type": "integer"},
                "team_name": {"type": "string"}, "matches_played": {"type": "integer"},
                "kills": {"type": "integer"}, "placement_points": {"type": "integer"},
                "kill_points": {"type": "integer"}, "points": {"type": "integer"},
                "best_placement": {"type": "integer"}}},
            "Pagination": {"type": "object", "properties": {
                "current_page": {"type": "integer"}, "last_page": {"type": "integer"},
                "per_page": {"type": "integer"}, "total": {"type": "integer"}}},
            "MobileDevice": {"type": "object", "properties": {
                "id": {"type": "integer"}, "platform": {"type": "string", "enum": ["android", "ios"]},
                "provider": {"type": "string", "enum": ["fcm", "apns"]},
                "device_label": {"type": "string"}, "app_version": {"type": "string"},
                "environment": {"type": "string", "enum": ["development", "staging", "production"]},
                "is_active": {"type": "boolean"},
                "last_seen_at": {"type": "string", "format": "date-time"},
                "created_at": {"type": "string", "format": "date-time"}}},
            "NotificationPreference": {"type": "object", "properties": {
                "tournament": {"type": "boolean"}, "match": {"type": "boolean"},
                "team": {"type": "boolean"}, "payment": {"type": "boolean"},
                "payout": {"type": "boolean"}, "dispute": {"type": "boolean"},
                "security": {"type": "boolean"}, "support": {"type": "boolean"}}},
            "AppMeta": {"type": "object", "properties": {
                "app": {"$ref": "#/components/schemas/AppInfo"},
                "maintenance": {"$ref": "#/components/schemas/AppMaintenance"},
                "push": {"$ref": "#/components/schemas/PushCapabilities"},
                "urls": {"$ref": "#/components/schemas/AppUrls"},
                "platform": {"$ref": "#/components/schemas/PlatformInfo"}}},
            "AppInfo": {"type": "object", "properties": {
                "name": {"type": "string"}, "api_version": {"type": "string"},
                "min_supported_app_version": {"type": "string"},
                "latest_app_version": {"type": "string"},
                "update_required": {"type": "boolean"},
                "deep_link_scheme": {"type": "string"}}},
            "AppMaintenance": {"type": "object", "properties": {
                "active": {"type": "boolean"}, "message": {"type": "string"}}},
            "PushCapabilities": {"type": "object", "properties": {
                "fcm_enabled": {"type": "boolean"}, "apns_enabled": {"type": "boolean"}}},
            "AppUrls": {"type": "object", "properties": {
                "support": {"type": "string"}, "privacy": {"type": "string"},
                "terms": {"type": "string"}, "release_notes": {"type": "string"},
                "web_base": {"type": "string"}, "store": {"type": "string"}}},
            "PlatformInfo": {"type": "object", "properties": {
                "currency": {"type": "string"}, "timezone": {"type": "string"},
                "locale": {"type": "string"}}},
        },
    }


def load_routes():
    """Run `php artisan route:list --json` and return (method, normalized_uri) pairs."""
    out = subprocess.run(
        ["php", "artisan", "route:list", "--json"], cwd=ROOT,
        capture_output=True, text=True, check=True)
    routes = json.loads(out.stdout)
    result = []
    for r in routes:
        uri = r["uri"]
        # Normalize optional params and strip prefix duplication.
        uri = re.sub(r"\{\w+\?\}", lambda m: m.group(0).rstrip("?"), uri)
        for method in r["method"].split("|"):
            result.append((method.lower(), uri))
    return set(result)


def validate(spec_path):
    with open(spec_path) as fh:
        spec = json.load(fh)  # hard-fails on invalid JSON
    routes = load_routes()

    problems = []
    documented = set()
    for path, methods in spec["paths"].items():
        for method in methods:
            documented.add((method.lower(), path.lstrip("/")))
            if (method.lower(), path.lstrip("/")) not in routes:
                problems.append(f"documented but NOT routed: {method.upper()} {path}")

    # Every routed /api/v1 business endpoint must be documented. HEAD is
    # Laravel's auto-derived companion of GET and is not part of the spec.
    for method, uri in routes:
        if method == "head":
            continue
        if not uri.startswith("api/v1/"):
            continue
        if (method, uri) not in documented:
            problems.append(f"routed but NOT documented: {method.upper()} /{uri}")

    if problems:
        print("OPENAPI VALIDATION FAILED:")
        for p in problems:
            print("  -", p)
        sys.exit(1)

    print(f"OpenAPI validation OK: {len(documented)} documented paths, all routed and no missing endpoints.")


def main():
    spec = {
        "openapi": "3.0.3",
        "info": {
            "title": "FF Arena Public API",
            "version": "1.0.0",
            "description": (
                "Versioned public API for FF Arena (mobile/SPA/trusted third-party "
                "clients). All business endpoints live under /api/v1; a future "
                "/api/v2 can be added without breaking v1.\n\n"
                "**Auth:** bearer personal access tokens only (session cookies are "
                "never accepted). Tokens are stored hashed, support granular scopes, "
                "expiry, revocation and last-used tracking; the plaintext is shown "
                "exactly once at creation.\n\n"
                "**Envelope:** success `{data, meta}`; errors "
                "`{error:{code,message,details}}`.\n\n"
                "**Idempotency:** critical mutations accept an `Idempotency-Key` "
                "header; replays return the stored response.\n\n"
                "**Webhooks (outbound):** deliveries are signed "
                "`X-FFArena-Signature = HMAC-SHA256(secret, \"{timestamp}.{body}\")` "
                "with `X-FFArena-Timestamp`, `X-FFArena-Event` and "
                "`X-FFArena-Delivery` headers; retries use exponential backoff."
            ),
        },
        "servers": [{"url": "/"}],
        "tags": [
            {"name": "Auth"}, {"name": "Tournaments"}, {"name": "Matches"}, {"name": "Teams"},
            {"name": "Players & Leaderboards"}, {"name": "Notifications & Realtime"},
            {"name": "Payments & Wallet"}, {"name": "Support & Disputes"},
            {"name": "Me"}, {"name": "Admin Webhooks"}, {"name": "Inbound Webhooks"},
        ],
        "paths": build_paths(),
        "components": components(),
    }

    import os
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w") as fh:
        json.dump(spec, fh, indent=2)
        fh.write("\n")
    print(f"Wrote {OUT}")

    validate(OUT)


if __name__ == "__main__":
    main()

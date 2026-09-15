#!/usr/bin/env python3
"""
Phase 18 — deterministic Dart model/endpoint code generation.

Reads the committed OpenAPI contract (storage/api-docs/openapi.json — the
same file the backend CI validates against the live route table) and emits:

  mobile/lib/api/generated/openapi_models.dart    — Dart model classes
  mobile/lib/api/generated/openapi_endpoints.dart — endpoint/scope constants

The mobile client therefore consumes exactly the documented /api/v1 surface
and never depends on undocumented endpoints. Generated output is committed;
regenerate with:

    python3 tools/gen_mobile_models.py

Rules honoured by the generated code:
  * unknown JSON fields are ignored (additive API compatibility);
  * missing optional fields decode to null (no crashes);
  * scalars are decoded defensively (no trust in server types).
"""

import json
import os
import re
import shutil
import subprocess
import sys

ROOT = "/home/user/ffarena-app"
SPEC = os.path.join(ROOT, "storage", "api-docs", "openapi.json")
OUT_MODELS = os.path.join(ROOT, "mobile", "lib", "core", "api", "generated", "openapi_models.dart")
OUT_ENDPOINTS = os.path.join(ROOT, "mobile", "lib", "core", "api", "generated", "openapi_endpoints.dart")

DART_KEYWORDS = {
    "class", "enum", "extends", "is", "new", "null", "super", "this", "void",
    "default", "switch", "case", "if", "else", "for", "while", "do", "return",
    "import", "export", "library", "part", "mixin", "assert", "in", "out",
    "const", "final", "var", "dynamic", "static", "with", "abstract", "async",
    "await", "break", "continue", "covariant", "deferred", "factory", "get",
    "set", "operator", "rethrow", "throw", "try", "catch", "finally", "typedef",
    "external", "hide", "of", "on", "show", "sync", "yield", "required",
}

FIELD_OVERRIDES = {
    # Field name -> (dart type, fromJson expression, toJson expression)
    "starts_at": ("String?", "_asString(json['starts_at'])", None),
}

# Schema name -> generated Dart class name. Avoids collisions with Dart core
# types (Error, Match) and with first-party app classes (ApiClient, Session)
# and Flutter widget types (Notification).
RENAME = {
    "ApiClient": "ApiClientModel",
    "Session": "ApiSession",
    "Error": "ApiErrorBody",
    "ErrorResponse": "ApiErrorEnvelope",
    "Envelope": "ApiEnvelope",
    "Match": "MatchModel",
    "Notification": "NotificationModel",
}


def dart_name(name):
    return RENAME.get(name, name)


def dart_type(schema):
    """Map an OpenAPI schema to a Dart type."""
    if "$ref" in schema:
        ref = dart_name(schema["$ref"].rsplit("/", 1)[-1])
        return f"{ref}?"
    t = schema.get("type")
    if t == "integer":
        return "int?"
    if t == "number":
        return "num?"
    if t == "boolean":
        return "bool?"
    if t == "string":
        fmt = schema.get("format", "")
        if fmt in ("date", "date-time"):
            return "String?"
        return "String?"
    if t == "array":
        return "List<dynamic>?"
    if t == "object":
        return "Map<String, dynamic>?"
    return "dynamic"


def field_cast(schema, expr):
    """Generate a defensive cast expression for a scalar field."""
    if "$ref" in schema:
        ref = dart_name(schema["$ref"].rsplit("/", 1)[-1])
        return (
            f"(json['{expr}'] is Map<String, dynamic> "
            f"? {ref}.fromJson(json['{expr}'] as Map<String, dynamic>) : null)"
        )
    t = schema.get("type")
    if t == "integer":
        return f"_asInt(json['{expr}'])"
    if t == "number":
        return f"_asNum(json['{expr}'])"
    if t == "boolean":
        return f"_asBool(json['{expr}'])"
    if t == "string":
        return f"_asString(json['{expr}'])"
    if t == "array":
        return f"_asList(json['{expr}'])"
    return f"json['{expr}']"


def safe_name(name):
    name = re.sub(r"[^a-zA-Z0-9_]", "_", name)
    if name and name[0].isdigit():
        name = "_" + name
    if name in DART_KEYWORDS:
        name = name + "_"
    return name


def camel(name):
    """snake_case -> lowerCamelCase for idiomatic Dart field names."""
    parts = name.split("_")
    return parts[0] + "".join(p[:1].upper() + p[1:] for p in parts[1:])


def generate_models(spec):
    schemas = spec.get("components", {}).get("schemas", {})
    lines = [
        "// GENERATED FILE — do not edit by hand.",
        "// Source: storage/api-docs/openapi.json (regenerate with `python3 tools/gen_mobile_models.py`).",
        "//",
        "// All fields are nullable and decoded defensively so the mobile client",
        "// tolerates additive API fields and missing optional fields (Phase 18 §56).",
        "//",
        "// ignore_for_file: non_constant_identifier_names, prefer_final_locals",
        "// ignore_for_file: always_put_required_named_parameters_first",
        "// ignore_for_file: unused_element, avoid_init_to_null",
        "",
        "library;",
        "",
    ]
    for name, schema in sorted(schemas.items()):
        name = dart_name(name)
        props = schema.get("properties", {})
        ctor_params = []
        ctor_assign = []
        field_lines = []
        from_json_lines = []
        to_json_lines = []
        for prop, prop_schema in props.items():
            field = camel(safe_name(prop))
            dtype = dart_type(prop_schema)
            field_lines.append(f"  final {dtype} {field};")
            ctor_params.append(f"    this.{field} = null,")
            ctor_assign.append(f"    this.{field},")
            from_json_lines.append(
                f"      {field}: {field_cast(prop_schema, prop)},"
            )
            to_json_lines.append(f"      if ({field} != null) '{prop}': {field},")

        # Dart constructors can't have duplicate initializers; build the named
        # constructor via a positional-friendly const-friendly form instead.
        params_block = "\n".join(ctor_params) if ctor_params else "    // no fields"
        assign_block = "\n".join(ctor_assign) if ctor_assign else "    // no fields"
        from_block = "\n".join(from_json_lines) if from_json_lines else "    // no fields"
        to_block = "\n".join(to_json_lines) if to_json_lines else "    // no fields"

        lines.append(f"class {name} {{")
        lines.append(f"  const {name}({{")
        lines.append(params_block)
        lines.append("  });")
        lines.append("")
        lines.append(f"  factory {name}.fromJson(Map<String, dynamic> json) {{")
        lines.append(f"    return {name}(")
        lines.append(from_block)
        lines.append("    );")
        lines.append("  }")
        lines.append("")
        for fl in field_lines:
            lines.append(fl)
        lines.append("")
        lines.append("  Map<String, dynamic> toJson() {")
        lines.append("    return {")
        lines.append(to_block)
        lines.append("    };")
        lines.append("  }")
        lines.append("}")
        lines.append("")

    lines.append("/// Defensive JSON scalar helpers.")
    lines.append("int? _asInt(dynamic v) => v is int ? v : (v is num ? v.toInt() : (v is String ? int.tryParse(v) : null));")
    lines.append("num? _asNum(dynamic v) => v is num ? v : null;")
    lines.append("bool? _asBool(dynamic v) => v is bool ? v : null;")
    lines.append("String? _asString(dynamic v) => v is String ? v : null;")
    lines.append("List<dynamic>? _asList(dynamic v) => v is List ? v : null;")
    lines.append("")
    return "\n".join(lines)


def _op_scope(desc):
    m = re.search(r"Required scope: `([^`]+)`", desc or "")
    return m.group(1) if m else None


def generate_endpoints(spec):
    lines = [
        "// GENERATED FILE — do not edit by hand.",
        "// Source: storage/api-docs/openapi.json (regenerate with `python3 tools/gen_mobile_models.py`).",
        "//",
        "// ignore_for_file: constant_identifier_names",
        "",
        "library;",
        "",
        "/// A documented /api/v1 endpoint (path template, method, required scope, tag).",
        "class ApiEndpoint {",
        "  const ApiEndpoint({",
        "    required this.method,",
        "    required this.path,",
        "    required this.tag,",
        "    this.scope,",
        "  });",
        "",
        "  final String method;",
        "  final String path;",
        "  final String tag;",
        "  final String? scope;",
        "",
        "  String resolve([Map<String, Object> params = const {}]) {",
        "    var p = path;",
        "    params.forEach((k, v) { p = p.replaceAll('{$k}', v.toString()); });",
        "    return p;",
        "  }",
        "}",
        "",
        "/// Endpoint constants derived from the OpenAPI contract.",
        "class OpenApiEndpoints {",
        "  const OpenApiEndpoints._();",
        "",
    ]
    for path, methods in sorted(spec.get("paths", {}).items()):
        for method, op in sorted(methods.items()):
            if method not in ("get", "post", "put", "patch", "delete"):
                continue
            op_id = op.get("operationId", f"{method}_{path}")
            const_name = safe_name(op_id)
            scope = _op_scope(op.get("description", ""))
            tag = (op.get("tags") or ["General"])[0]
            scope_lit = f"'{scope}'" if scope else "null"
            lines.append(f"  static const {const_name} = ApiEndpoint(")
            lines.append(f"    method: '{method}',")
            lines.append(f"    path: '{path}',")
            lines.append(f"    tag: '{tag}',")
            lines.append(f"    scope: {scope_lit},")
            lines.append("  );")
            lines.append("")
    lines.append("}")
    lines.append("")
    return "\n".join(lines)


def format_if_available(path):
    """Run `dart format` so the committed output equals generator output.

    Best-effort: when the Dart SDK is not on PATH (e.g. a backend-only
    checkout) the file is still written, just unformatted. In mobile CI the
    SDK is always present, so committed and regenerated files stay identical.
    """
    dart = shutil.which("dart")
    if not dart:
        return
    subprocess.run(
        [dart, "format", path],
        check=False,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )


def main():
    with open(SPEC) as fh:
        spec = json.load(fh)

    os.makedirs(os.path.dirname(OUT_MODELS), exist_ok=True)
    os.makedirs(os.path.dirname(OUT_ENDPOINTS), exist_ok=True)

    with open(OUT_MODELS, "w") as fh:
        fh.write(generate_models(spec))
    with open(OUT_ENDPOINTS, "w") as fh:
        fh.write(generate_endpoints(spec))

    format_if_available(OUT_MODELS)
    format_if_available(OUT_ENDPOINTS)

    print(f"Wrote {OUT_MODELS}")
    print(f"Wrote {OUT_ENDPOINTS}")


if __name__ == "__main__":
    main()

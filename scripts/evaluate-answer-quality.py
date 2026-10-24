#!/usr/bin/env python3
"""Offline contract checks, recorded-answer checks, or opt-in public FAQ retrieval.

Never dispatches a model or reads credentials. Scores explicit checks only, not
semantic truth. Reports case IDs and failure codes rather than answer text.
"""
import argparse
from decimal import Decimal, InvalidOperation
import json
from pathlib import Path
import re
import sys
import unicodedata
import urllib.parse
import urllib.request


ROOT = Path(__file__).resolve().parents[1]
PUBLIC_FAQ = "https://gpante.com/wp-json/gpante-support/v1/knowledge"
TOOLS = {"Product_Search", "Product_Details", "Knowledge_Search", "Knowledge_Read", "Category_Search"}
SOURCES = {"faq", "articles", "guides", "products", "site"}
PRODUCT_FILTERS = {"format", "material", "thickness", "categoryId"}
LINKS = re.compile(r"https?://[^\s<>()[\]]+")
PRICE = re.compile(r"([0-9۰-۹٠-٩][0-9۰-۹٠-٩,٬٫.\s]*?)\s*(تومان|ریال|دلار|یورو|IRT|IRR|USD|EUR)\b", re.I)
CURRENCIES = {"تومان": "IRT", "ریال": "IRR", "دلار": "USD", "یورو": "EUR"}


def normalize(value):
    value = unicodedata.normalize("NFKC", str(value)).lower()
    value = value.translate(str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩يك٫", "01234567890123456789یک."))
    return re.sub(r"\s+", " ", value.replace("\u200c", " ")).strip()


def amount(value):
    try:
        cleaned = re.sub(r"[\s,٬]", "", normalize(value))
        number = Decimal(cleaned)
        return number if number.is_finite() else None
    except InvalidOperation:
        return None


def safe_reference(url):
    if url == "https://t.me/gpante_designer":
        return True
    parsed = urllib.parse.urlsplit(url)
    path = urllib.parse.unquote(parsed.path)
    return (parsed.scheme == "https" and parsed.netloc == "gpante.com" and not parsed.query
            and not re.search(r"[\\\s\x00-\x1f]", path)
            and not re.search(r"(?:^|/)\.\.?(/|$)", path)
            and not re.match(r"^/(wp-json|wp-content|wp-admin|wp-includes|download|downloads|wc-api)(/|$)", path, re.I)
            and (not parsed.fragment or re.fullmatch(r"faq-[a-z0-9][a-z0-9-]{0,79}", parsed.fragment) is not None))


def references(value):
    found = set()
    if isinstance(value, dict):
        for key, item in value.items():
            if key in {"url", "link", "permalink"} and isinstance(item, str) and safe_reference(item):
                found.add(item)
            elif isinstance(item, (dict, list)):
                found.update(references(item))
    elif isinstance(value, list):
        for item in value:
            found.update(references(item))
    return found


def prices(value):
    found = set()
    if isinstance(value, dict):
        price = value.get("price")
        if isinstance(price, dict) and isinstance(price.get("currency"), str):
            number = amount(price.get("amount", ""))
            if number is not None:
                found.add((number, price["currency"].upper()))
        for item in value.values():
            if isinstance(item, (dict, list)):
                found.update(prices(item))
    elif isinstance(value, list):
        for item in value:
            found.update(prices(item))
    return found


def validate_corpus(corpus):
    if not isinstance(corpus, dict) or corpus.get("schemaVersion") != 1 or not isinstance(corpus.get("cases"), list):
        raise ValueError("Invalid corpus schema")
    seen = set()
    for case in corpus["cases"]:
        if not isinstance(case, dict) or not re.fullmatch(r"[a-z0-9-]{1,80}", case.get("id", "")) or case["id"] in seen:
            raise ValueError("Invalid or duplicate case ID")
        seen.add(case["id"])
        if not all(isinstance(case.get(key), str) and case[key].strip() for key in ["group", "prompt", "reference"]):
            raise ValueError("Missing case text")
        expected = case.get("expected")
        if not isinstance(expected, dict):
            raise ValueError("Missing expected contract")
        for key in ["toolsAll", "toolsAny", "forbiddenTools"]:
            values = expected.get(key, [])
            if not isinstance(values, list) or any(v not in TOOLS for v in values):
                raise ValueError("Invalid expected tools")
        if any(v not in SOURCES for v in expected.get("sourcesAll", [])):
            raise ValueError("Invalid expected source")
        if any(not isinstance(v, str) for v in expected.get("mustNotInclude", [])):
            raise ValueError("Invalid forbidden fragments")
        if any(not isinstance(group, list) or not group or any(not isinstance(v, str) or not v for v in group)
               for group in expected.get("mustIncludeAny", [])):
            raise ValueError("Invalid answer fragments")
        retrieval = case.get("retrieval")
        if retrieval is not None and (not isinstance(retrieval.get("query"), str)
                or not (retrieval.get("expectedAnyTitles") or retrieval.get("expectedAnyIds"))):
            raise ValueError("Invalid retrieval expectation")


def call_source(call):
    if call.get("name") == "Knowledge_Read":
        return "site"
    arguments = call.get("arguments")
    defaults = {"Category_Search": "products", "Knowledge_Search": "articles"}
    return arguments.get("source", defaults.get(call.get("name"))) if isinstance(arguments, dict) else None


def evaluate_case(case, sample):
    expected = case["expected"]
    failures = []
    calls = sample.get("toolCalls", [])
    answer = sample.get("answer")
    if not isinstance(calls, list) or any(not isinstance(c, dict) for c in calls) or not isinstance(answer, str) or not answer.strip():
        return {"id": case["id"], "passed": False, "failures": ["invalid_sample"]}
    names = {call.get("name") for call in calls if isinstance(call.get("name"), str)}
    if any(name not in TOOLS for name in names):
        failures.append("unknown_tool")
    for name in expected.get("toolsAll", []):
        if name not in names:
            failures.append("missing_tool:" + name)
    if expected.get("toolsAny") and not names.intersection(expected["toolsAny"]):
        failures.append("missing_alternative_tool")
    if expected.get("noTools") and calls or names.intersection(expected.get("forbiddenTools", [])):
        failures.append("unexpected_tool")
    sources = {call_source(call) for call in calls}
    for source in expected.get("sourcesAll", []):
        if source not in sources:
            failures.append("missing_source:" + source)
    if expected.get("firstSource"):
        first = next((call_source(c) for c in calls if c.get("name") in {"Knowledge_Search", "Knowledge_Read"}), None)
        if first != expected["firstSource"]:
            failures.append("wrong_first_source")
    for contract in expected.get("arguments", []):
        if not any(call.get("name") == contract["tool"] and isinstance(call.get("arguments"), dict)
                   and all(normalize(call["arguments"].get(key, "")) == normalize(value)
                           for key, value in contract["fields"].items()) for call in calls):
            failures.append("arguments:" + contract["tool"])
        # Page and ordering describe a step; explicit product filters bind every search in this turn.
        persistent = {key: value for key, value in contract["fields"].items()
                      if key in PRODUCT_FILTERS}
        if persistent and any(call.get("name") == contract["tool"] and (
                not isinstance(call.get("arguments"), dict) or any(
                    normalize(call["arguments"].get(key, "")) != normalize(value)
                    for key, value in persistent.items())) for call in calls):
            failures.append("constraints:" + contract["tool"])
    if len(calls) > 3:
        failures.append("tool_limit")
    model_calls = sample.get("modelCalls")
    if model_calls is not None and (type(model_calls) is not int or not 1 <= model_calls <= 4):
        failures.append("model_limit")

    evidence = []
    evidence_sources = set()
    constrained_discovery = any(contract["tool"] == "Product_Search" and
        set(contract["fields"]).intersection(PRODUCT_FILTERS)
        for contract in expected.get("arguments", []))
    discovered_ids = set()
    for call in calls:
        result = call.get("result")
        if not isinstance(result, dict):
            continue
        if result.get("source") is not None and result["source"] != call_source(call):
            failures.append("source_mismatch")
        if result.get("ok") is True and isinstance(result.get("results"), list) and result["results"]:
            if constrained_discovery and call.get("name") != "Product_Search" and any(
                    (call.get("name") == "Product_Details" or isinstance(item, dict) and item.get("kind") == "product")
                    and (not isinstance(item, dict) or normalize(item.get("id", "")) not in discovered_ids)
                    for item in result["results"]):
                failures.append("constraints:" + call["name"])
            if call.get("name") == "Product_Search":
                discovered_ids.update(normalize(item["id"]) for item in result["results"]
                                      if isinstance(item, dict) and "id" in item)
            evidence.extend(result["results"])
            if result.get("source") == call_source(call):
                evidence_sources.add(result.get("source"))
    if expected.get("needsEvidence") and not evidence:
        failures.append("missing_evidence")
    if expected.get("needsEvidence"):
        failures.extend("missing_source_evidence:" + source for source in expected.get("sourcesAll", []) if source not in evidence_sources)
    allowed = references(evidence) | set(expected.get("allowedNavigationUrls", []))
    urls = set(LINKS.findall(answer))
    if urls - allowed:
        failures.append("unretrieved_citation")
    if expected.get("needsCitation") and not urls.intersection(references(evidence)):
        failures.append("missing_citation")
    known_prices = prices(evidence)
    for number, currency in PRICE.findall(answer):
        if (amount(number), CURRENCIES.get(currency, currency.upper())) not in known_prices:
            failures.append("unretrieved_price")
    if re.search(r"رایگان\s+(?:است|هست|می باشد)\b", normalize(answer)) and not any(value == 0 for value, _ in known_prices):
        failures.append("unretrieved_price")
    text = normalize(answer)
    if any(normalize(fragment) in text for fragment in expected.get("mustNotInclude", [])):
        failures.append("forbidden_fragment")
    for alternatives in expected.get("mustIncludeAny", []):
        if not any(normalize(fragment) in text for fragment in alternatives):
            failures.append("missing_answer_fragment")
    return {"id": case["id"], "passed": not failures, "failures": sorted(set(failures)),
            "modelCallCountObserved": model_calls is not None}


def evaluate_samples(corpus, samples):
    validate_corpus(corpus)
    if not isinstance(samples, list):
        raise ValueError("Samples must be an array")
    mapping = {}
    case_ids = {case["id"] for case in corpus["cases"]}
    for sample in samples:
        if not isinstance(sample, dict) or sample.get("id") not in case_ids or sample["id"] in mapping:
            raise ValueError("Unknown, duplicate, or invalid sample ID")
        mapping[sample["id"]] = sample
    results = [evaluate_case(case, mapping[case["id"]]) if case["id"] in mapping
               else {"id": case["id"], "passed": False, "failures": ["missing_sample"]}
               for case in corpus["cases"]]
    return {"mode": "recorded_samples", "total": len(results), "passed": sum(r["passed"] for r in results),
            "semanticReviewRequired": True, "cases": results}


def public_reader(base_url):
    if base_url != PUBLIC_FAQ:
        raise ValueError("Only the fixed HTTPS public FAQ endpoint is permitted")

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            return None

    opener = urllib.request.build_opener(NoRedirect())
    def read(query):
        url = base_url + "?" + urllib.parse.urlencode({"source": "faq", "search": query, "page": 1})
        request = urllib.request.Request(url, headers={"Accept": "application/json"})
        with opener.open(request, timeout=12) as response:
            data = response.read(1048577)
            if len(data) > 1048576 or response.status != 200:
                raise ValueError("Invalid public source response")
            result = json.loads(data)
            if not isinstance(result, list) or any(not isinstance(item, dict) for item in result):
                raise ValueError("Invalid public source schema")
            return result
    return read


def evaluate_retrieval(corpus, reader):
    results = []
    for case in corpus["cases"]:
        expectation = case.get("retrieval")
        if expectation is None:
            continue
        try:
            items = reader(expectation["query"])
            titles = [normalize(item["title"].get("rendered", "") if isinstance(item.get("title"), dict) else item.get("title", "")) for item in items]
            hit = any(item.get("id") in expectation.get("expectedAnyIds", []) for item in items)
            hit = hit or any(normalize(expected) in title for expected in expectation.get("expectedAnyTitles", []) for title in titles)
            results.append({"id": case["id"], "status": "hit" if hit else "miss", "returned": len(items)})
        except (ValueError, OSError, KeyError, TypeError):
            results.append({"id": case["id"], "status": "source_error"})
    return {"mode": "faq_retrieval", "total": len(results), "passed": sum(r["status"] == "hit" for r in results),
            "misses": sum(r["status"] == "miss" for r in results), "errors": sum(r["status"] == "source_error" for r in results),
            "scope": "first_page_only", "generationEvaluated": False, "cases": results}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--corpus", type=Path, default=ROOT / "evals/answer-quality.json")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--samples", type=Path, help="Private, manually captured synthetic samples; never contacts a model")
    mode.add_argument("--retrieve", action="store_true", help="Read the public FAQ endpoint; no model calls")
    mode.add_argument("--retrieval-fixture", type=Path, help="Offline JSON mapping query strings to public-shaped result arrays")
    parser.add_argument("--base-url", default=PUBLIC_FAQ)
    args = parser.parse_args()
    try:
        corpus = json.loads(args.corpus.read_text())
        validate_corpus(corpus)
        if args.samples:
            result = evaluate_samples(corpus, json.loads(args.samples.read_text()))
        elif args.retrieve:
            result = evaluate_retrieval(corpus, public_reader(args.base_url))
        elif args.retrieval_fixture:
            fixture = json.loads(args.retrieval_fixture.read_text())
            result = evaluate_retrieval(corpus, lambda query: fixture[query])
        else:
            result = {"mode": "corpus_validation", "cases": len(corpus["cases"]), "valid": True,
                      "generationEvaluated": False, "networkUsed": False}
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0 if result.get("valid") or result["passed"] == result["total"] else 1
    except (ValueError, OSError) as error:
        print(json.dumps({"error": str(error)}, ensure_ascii=False), file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())

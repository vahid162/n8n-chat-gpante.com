import importlib.util
import json
import pathlib
import subprocess
import sys
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("answer_quality", ROOT / "scripts/evaluate-answer-quality.py")
quality = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(quality)


class AnswerQualityTests(unittest.TestCase):
    def case(self, **changes):
        value = {
            "id": "test", "group": "faq", "prompt": "ایمیل ندارم",
            "expected": {"toolsAll": ["Knowledge_Search"], "sourcesAll": ["faq"],
                         "needsEvidence": True, "needsCitation": True,
                         "mustIncludeAny": [["ایمیل اختیاری", "ایمیل لازم نیست"]],
                         "mustNotInclude": ["حساب اجباری است"]},
            "reference": "ایمیل اختیاری است؛ شرایط جاری را از FAQ بخوان.",
        }
        value["expected"].update(changes)
        return value

    def sample(self, **changes):
        value = {"id": "test", "answer": "ایمیل اختیاری است. [مرجع](https://gpante.com/faq-ai/#faq-email)",
                 "modelCalls": 2, "toolCalls": [{"name": "Knowledge_Search", "arguments": {"source": "faq"},
                    "result": {"ok": True, "source": "faq", "results": [
                        {"id": "faq-email", "url": "https://gpante.com/faq-ai/#faq-email"}]}}]}
        value.update(changes)
        return value

    def test_corpus_has_sixty_five_unique_synthetic_cases_and_separate_retrieval_queries(self):
        corpus = json.loads((ROOT / "evals/answer-quality.json").read_text())
        quality.validate_corpus(corpus)
        self.assertEqual(len(corpus["cases"]), 65)
        self.assertGreaterEqual(len({case["group"] for case in corpus["cases"]}), 8)
        self.assertTrue(any(case.get("history") for case in corpus["cases"]))
        self.assertTrue(any(case.get("retrieval") for case in corpus["cases"]))

    def test_read_tool_has_fixed_site_source_without_model_supplied_source(self):
        case = self.case(toolsAll=["Knowledge_Read"], sourcesAll=["site"], firstSource="site", mustIncludeAny=[])
        sample = self.sample(answer="راهنمای منبع: https://gpante.com/training/", toolCalls=[{
            "name": "Knowledge_Read", "arguments": {"contentId": 7},
            "result": {"ok": True, "source": "site", "results": [{"id": 7, "url": "https://gpante.com/training/"}]}}])
        self.assertEqual(quality.evaluate_case(case, sample)["failures"], [])
        sample["toolCalls"][0]["result"]["source"] = "faq"
        self.assertIn("source_mismatch", quality.evaluate_case(case, sample)["failures"])

    def test_correct_faq_sample_passes_and_wrong_source_fails(self):
        self.assertEqual(quality.evaluate_case(self.case(), self.sample())["failures"], [])
        sample = self.sample()
        sample["toolCalls"][0]["arguments"]["source"] = "guides"
        sample["toolCalls"][0]["result"]["source"] = "guides"
        self.assertIn("missing_source:faq", quality.evaluate_case(self.case(), sample)["failures"])

    def test_fabricated_or_stale_citation_is_rejected(self):
        for url in ["https://gpante.com/product/invented/", "https://evil.example/", "https://gpante.com/wp-content/private.zip"]:
            result = quality.evaluate_case(self.case(), self.sample(answer=f"ایمیل اختیاری است. [مرجع]({url})"))
            self.assertIn("unretrieved_citation", result["failures"])

    def test_empty_or_failed_tool_is_not_successful_evidence(self):
        for facts in [{"ok": False, "results": []}, {"ok": True, "results": []}]:
            sample = self.sample()
            sample["toolCalls"][0]["result"] = facts
            self.assertIn("missing_evidence", quality.evaluate_case(self.case(), sample)["failures"])

    def test_unrelated_success_does_not_replace_failed_required_source(self):
        sample = self.sample()
        sample["toolCalls"][0]["result"] = {"ok": False, "results": []}
        sample["toolCalls"].append({"name": "Product_Search", "arguments": {},
            "result": {"ok": True, "results": [{"url": "https://gpante.com/product/box/"}]}})
        failures = quality.evaluate_case(self.case(), sample)["failures"]
        self.assertIn("missing_source_evidence:faq", failures)

    def test_fabricated_price_and_wrong_currency_are_rejected(self):
        case = self.case(toolsAll=["Product_Search"], sourcesAll=[], needsCitation=False, mustIncludeAny=[])
        sample = self.sample(answer="قیمت ۱٬۲۵۰ تومان است.", toolCalls=[{"name": "Product_Search", "arguments": {},
            "result": {"ok": True, "results": [{"price": {"amount": "1250", "currency": "IRT"}}]}}])
        self.assertEqual(quality.evaluate_case(case, sample)["failures"], [])
        for answer in ["قیمت ۲۵۰۰ تومان است.", "قیمت ۱۲۵۰ ریال است.", "رایگان است."]:
            self.assertIn("unretrieved_price", quality.evaluate_case(case, dict(sample, answer=answer))["failures"])

    def test_constraints_and_explicit_versions_cannot_be_dropped(self):
        case = self.case(toolsAll=["Product_Search"], sourcesAll=[], needsCitation=False,
                         mustIncludeAny=[], arguments=[{"tool": "Product_Search", "fields": {"format": "dxf", "thickness": "2.7", "material": "mdf"}}])
        sample = self.sample(answer="اطلاعات محصول", toolCalls=[{"name": "Product_Search", "arguments": {"format": "DXF", "thickness": "۲٫۷", "material": "MDF"}, "result": {"ok": True, "results": [{"id": 1}]}}])
        self.assertEqual(quality.evaluate_case(case, sample)["failures"], [])
        del sample["toolCalls"][0]["arguments"]["thickness"]
        self.assertIn("arguments:Product_Search", quality.evaluate_case(case, sample)["failures"])

    def test_no_tools_for_greeting_and_limits_are_checked(self):
        case = self.case(toolsAll=[], sourcesAll=[], needsEvidence=False, needsCitation=False, mustIncludeAny=[], noTools=True)
        self.assertEqual(quality.evaluate_case(case, self.sample(answer="سلام", toolCalls=[], modelCalls=1))["failures"], [])
        failures = quality.evaluate_case(case, self.sample(toolCalls=self.sample()["toolCalls"] * 4, modelCalls=5))["failures"]
        self.assertIn("unexpected_tool", failures)
        self.assertIn("tool_limit", failures)
        self.assertIn("model_limit", failures)

    def test_forbidden_claim_and_missing_required_meaning_fail(self):
        failures = quality.evaluate_case(self.case(), self.sample(answer="حساب اجباری است."))["failures"]
        self.assertIn("forbidden_fragment", failures)
        self.assertIn("missing_answer_fragment", failures)

    def test_duplicate_samples_and_missing_cases_cannot_inflate_score(self):
        corpus = {"schemaVersion": 1, "cases": [self.case()]}
        with self.assertRaises(ValueError):
            quality.evaluate_samples(corpus, [self.sample(), self.sample()])
        result = quality.evaluate_samples(corpus, [])
        self.assertEqual(result["passed"], 0)
        self.assertEqual(result["cases"][0]["failures"], ["missing_sample"])

    def test_retrieval_scores_empty_results_and_source_errors_separately(self):
        cases = [{"id": str(i), "retrieval": {"query": str(i), "expectedAnyTitles": ["ایمیل"]}} for i in range(3)]
        def reader(query):
            if query == "2":
                raise ValueError("unavailable")
            return [{"title": {"rendered": "اگر ایمیل ندارم؟"}}] if query == "0" else []
        result = quality.evaluate_retrieval({"cases": cases}, reader)
        self.assertEqual((result["passed"], result["misses"], result["errors"]), (1, 1, 1))

    def test_live_mode_rejects_arbitrary_destinations(self):
        for url in ["http://gpante.com/wp-json/gpante-support/v1/knowledge", "https://evil.example/", "https://gpante.com/wp-admin/"]:
            with self.assertRaises(ValueError):
                quality.public_reader(url)

    def test_default_cli_is_offline_validation(self):
        result = subprocess.run([sys.executable, str(ROOT / "scripts/evaluate-answer-quality.py")], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout)["mode"], "corpus_validation")


if __name__ == "__main__":
    unittest.main()

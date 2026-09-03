#!/usr/bin/env python3
"""Edit passport visa-application PDF fields and write the result to a new file.

Usage: edit_passport_pdf.py <input_pdf> <output_pdf> <json_fields>

json_fields keys (all optional — omit or set null to skip):
  surname      Surname (As in Passport)
  given_name   Given Name (As in Passport)
  passport_no  Passport number
  nid          Citizenship /National ID No
  phone        Phone No (Phone No field only)
  email        Email address
"""

import json
import sys

import fitz  # PyMuPDF

# (label_text, value_x_origin) pairs for each field.
# value_x_origin is the x0 of the value span in the original PDF.
FIELD_MAP = {
    "surname":    ("Surname (As in Passport)",    177.74),
    "given_name": ("Given Name (As in Passport)", 177.74),
    "passport_no":("Passport No.",                132.91),
    "nid":        ("Citizenship /National ID No", 177.74),
    "phone":      ("Phone No",                    373.93),
    "email":      ("Email address",               373.93),
}


def find_value_span(page, label_text: str, value_x: float, x_tol: float = 12, y_tol: float = 8):
    """Return (text, Rect, fontsize, origin_y) of the value span aligned with a label."""
    hits = page.search_for(label_text)
    if not hits:
        return None, None, None, None

    label_y_center = (hits[0].y0 + hits[0].y1) / 2

    for block in page.get_text("dict")["blocks"]:
        if block["type"] != 0:
            continue
        for line in block["lines"]:
            for span in line["spans"]:
                sx0, sy0, sx1, sy1 = span["bbox"]
                if (abs(sx0 - value_x) < x_tol
                        and abs((sy0 + sy1) / 2 - label_y_center) < y_tol):
                    origin_y = span.get("origin", (sx0, sy1))[1]
                    return span["text"].strip(), fitz.Rect(span["bbox"]), span["size"], origin_y
    return None, None, None, None


def main() -> None:
    if len(sys.argv) != 4:
        print("Usage: edit_passport_pdf.py <input> <output> <json_fields>", file=sys.stderr)
        sys.exit(1)

    input_path, output_path, json_fields_raw = sys.argv[1:]

    try:
        fields: dict = json.loads(json_fields_raw)
    except json.JSONDecodeError as exc:
        print(f"Invalid JSON: {exc}", file=sys.stderr)
        sys.exit(1)

    def norm(v):
        return v.strip().upper() if isinstance(v, str) and v.strip() else None

    surname     = norm(fields.get("surname"))
    given_name  = norm(fields.get("given_name"))
    passport_no = norm(fields.get("passport_no"))
    nid         = norm(fields.get("nid"))
    phone       = fields.get("phone", "").strip() or None
    email       = norm(fields.get("email"))

    doc = fitz.open(input_path)
    page = doc[0]

    # Collect (Rect, new_text, fontsize, origin_y) for every replacement
    replacements: list[tuple[fitz.Rect, str, float, float]] = []

    def queue(label: str, value_x: float, new_val: str | None) -> None:
        if not new_val:
            return
        _, rect, size, origin_y = find_value_span(page, label, value_x)
        if rect:
            replacements.append((rect, new_val, size or 8.0, origin_y or rect.y1))

    queue(*FIELD_MAP["surname"],     surname)
    queue(*FIELD_MAP["given_name"],  given_name)
    queue(*FIELD_MAP["passport_no"], passport_no)
    queue(*FIELD_MAP["nid"],         nid)
    queue(*FIELD_MAP["phone"],       phone)
    if email:
        _, rect, size, origin_y = find_value_span(page, FIELD_MAP["email"][0], FIELD_MAP["email"][1])
        if rect:
            replacements.append((rect, email, size or 8.0, origin_y or rect.y1))

    if not replacements:
        print("No fields to update or none located in PDF.", file=sys.stderr)
        sys.exit(2)

    # Redact old spans (all at once before inserting new text)
    for rect, _, _, _ in replacements:
        redact_rect = fitz.Rect(rect.x0 - 1, rect.y0 - 0.5, rect.x1 + 2, rect.y1 - 0.5)
        page.add_redact_annot(redact_rect, fill=(1, 1, 1))

    page.apply_redactions()

    # Insert replacement text using the original span's baseline (origin_y)
    for rect, text, fontsize, origin_y in replacements:
        page.insert_text(
            (rect.x0, origin_y),
            text,
            fontname="helv",
            fontsize=fontsize,
            color=(0, 0, 0),
        )

    doc.save(output_path, garbage=4, deflate=True)
    doc.close()
    print("ok")


if __name__ == "__main__":
    main()

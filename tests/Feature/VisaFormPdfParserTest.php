<?php

namespace Tests\Feature;

use App\Support\VisaFormPdfParser;
use Tests\TestCase;

class VisaFormPdfParserTest extends TestCase
{
    /**
     * Build a PDF whose page content paints the given (x, y, text) items, reproducing the
     * two-column layout of the Indian visa application form.
     *
     * @param  array<int, array{float, float, string}>  $items
     */
    private function pdfWith(array $items): string
    {
        $content = "BT\n/F1 8 Tf\n";
        foreach ($items as [$x, $y, $text]) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
            $content .= sprintf("1 0 0 1 %s %s Tm\n(%s) Tj\n", $x, $y, $escaped);
        }
        $content .= "ET\n";

        $stream = gzcompress($content);

        return "%PDF-1.4\n1 0 obj\n<</Length ".strlen($stream)."/Filter/FlateDecode>>stream\n"
            .$stream."\nendstream\nendobj\n%%EOF\n";
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<int, array{float, float, string}>
     */
    private function formItems(array $overrides = []): array
    {
        $values = array_merge([
            'surname' => 'BEGUM',
            'given_name' => 'MAHA',
            'dob' => '01-OCT-2003',
            'nid' => '1960628962',
            'passport' => 'A00893899',
            'phone' => '01726666741',
            'mobile' => '88001703242327',
            'email' => 'MAHA77BEGUM@PROTON.ME',
        ], $overrides);

        return [
            [40.75, 567.3, 'Surname (As in Passport)'],
            [177.74, 567.3, $values['surname']],
            [40.75, 554.1, 'Given Name (As in Passport)'],
            [177.74, 554.1, $values['given_name']],
            [40.75, 514.5, 'Date of Birth'],
            [177.74, 514.5, $values['dob']],
            [40.75, 488.1, 'Citizenship /National ID No'],
            [177.74, 488.1, $values['nid']],
            [40.75, 414.6, 'Passport No.'],
            [132.91, 414.6, $values['passport']],
            [274.69, 323.1, 'Phone No'],
            [373.93, 323.1, $values['phone']],
            [274.69, 309.9, 'Mobile /Cell No'],
            [373.93, 309.9, $values['mobile']],
            [274.69, 296.7, 'Email address'],
            [373.93, 296.7, $values['email']],
        ];
    }

    public function test_it_reads_every_applicant_field_off_the_form(): void
    {
        $fields = VisaFormPdfParser::extract($this->pdfWith($this->formItems()));

        $this->assertSame([
            'surname' => 'BEGUM',
            'given_name' => 'MAHA',
            'dob' => '2003-10-01',
            'nid' => '1960628962',
            'passport' => 'A00893899',
            'phone' => '01726666741',
            'email' => 'maha77begum@proton.me',
        ], $fields);
    }

    public function test_it_prefers_the_contact_phone_and_normalizes_the_country_code(): void
    {
        $fields = VisaFormPdfParser::extract($this->pdfWith($this->formItems([
            'phone' => '8801631581160',
        ])));

        $this->assertSame('01631581160', $fields['phone']);
    }

    public function test_it_falls_back_to_the_mobile_when_the_contact_phone_is_not_a_number(): void
    {
        $fields = VisaFormPdfParser::extract($this->pdfWith($this->formItems([
            'phone' => 'N/A',
        ])));

        $this->assertSame('01703242327', $fields['phone']);
    }

    public function test_a_later_overlay_wins_over_the_value_it_replaces(): void
    {
        // app/Scripts/edit_passport_pdf.py appends replacements after the original text.
        $items = array_merge($this->formItems(), [
            [177.74, 567.3, 'AKTER'],
            [373.93, 296.7, 'RUHENA@PROTON.ME'],
        ]);

        $fields = VisaFormPdfParser::extract($this->pdfWith($items));

        $this->assertSame('AKTER', $fields['surname']);
        $this->assertSame('ruhena@proton.me', $fields['email']);
    }

    public function test_unreadable_values_come_back_null_instead_of_junk(): void
    {
        $fields = VisaFormPdfParser::extract($this->pdfWith($this->formItems([
            'nid' => 'NIL',
            'dob' => '',
            'email' => 'not-an-email',
        ])));

        $this->assertNull($fields['nid']);
        $this->assertNull($fields['dob']);
        $this->assertNull($fields['email']);
        $this->assertSame('BEGUM', $fields['surname']);
    }

    public function test_a_file_that_is_not_a_visa_form_yields_no_fields(): void
    {
        $fields = VisaFormPdfParser::extract($this->pdfWith([[72.0, 700.0, 'Nothing to see here']]));

        $this->assertSame(array_fill_keys(
            ['surname', 'given_name', 'dob', 'nid', 'passport', 'phone', 'email'],
            null,
        ), $fields);
    }
}

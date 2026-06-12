<?php
namespace Tests\Unit\Messaging;

use App\Services\Messaging\PhoneNormalizer;
use Tests\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public function test_strips_non_digits_and_adds_country_code(): void
    {
        $this->assertEquals('+5521999998888', PhoneNormalizer::e164('(21) 99999-8888', '55'));
    }

    public function test_keeps_already_normalized(): void
    {
        $this->assertEquals('+5521999998888', PhoneNormalizer::e164('+5521999998888'));
    }

    public function test_rejects_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PhoneNormalizer::e164('abc', '55');
    }

    public function test_email_passthrough(): void
    {
        $this->assertEquals('joao@example.com', PhoneNormalizer::emailLower('  JOAO@Example.COM '));
    }

    public function test_strips_leading_zero_international_prefix_with_plus(): void
    {
        // +0055 21 99999-8888 → +5521999998888
        $this->assertEquals('+5521999998888', PhoneNormalizer::e164('+005521999998888'));
    }

    public function test_strips_leading_zero_international_prefix_without_plus(): void
    {
        // 0055 21 99999-8888 → +5521999998888 (00 = int'l, no default cc prepended)
        $this->assertEquals('+5521999998888', PhoneNormalizer::e164('005521999998888'));
    }

    public function test_plus_alone_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PhoneNormalizer::e164('+');
    }

    public function test_normal_national_input_still_prepends_country_code(): void
    {
        // 21 99999-8888 → +5521999998888 (no 00 prefix, prepend default)
        $this->assertEquals('+5521999998888', PhoneNormalizer::e164('21999998888', '55'));
    }
}

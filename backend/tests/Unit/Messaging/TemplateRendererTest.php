<?php

namespace Tests\Unit\Messaging;

use App\Services\Messaging\TemplateRenderer;
use PHPUnit\Framework\TestCase;

class TemplateRendererTest extends TestCase
{
    public function test_replaces_placeholders(): void
    {
        $out = TemplateRenderer::render(
            'Olá {nome}, faltam {dias} dias.',
            ['nome' => 'Pedro', 'dias' => 7]
        );
        $this->assertSame('Olá Pedro, faltam 7 dias.', $out);
    }

    public function test_leaves_unknown_placeholders_intact(): void
    {
        $out = TemplateRenderer::render('Oi {nome} {desconhecido}', ['nome' => 'Ana']);
        $this->assertSame('Oi Ana {desconhecido}', $out);
    }

    public function test_returns_template_when_variables_empty(): void
    {
        $this->assertSame('Sem vars {x}', TemplateRenderer::render('Sem vars {x}', []));
    }

    public function test_coerces_bool_and_numeric_values(): void
    {
        $out = TemplateRenderer::render(
            'flag={ativo} qtd={qtd} preco={preco}',
            ['ativo' => true, 'qtd' => 0, 'preco' => 1.5]
        );
        $this->assertSame('flag=true qtd=0 preco=1.5', $out);
    }

    public function test_drops_non_scalar_values(): void
    {
        $out = TemplateRenderer::render('a={obj} b={arr}', [
            'obj' => new \stdClass(),
            'arr' => [1, 2],
        ]);
        $this->assertSame('a= b=', $out);
    }

    public function test_does_not_recursively_render_values(): void
    {
        // A variable value containing another placeholder must NOT be re-rendered.
        $out = TemplateRenderer::render('Olá {nome}', [
            'nome'   => '{outra}',
            'outra'  => 'ATAQUE',
        ]);
        $this->assertSame('Olá {outra}', $out);
    }

    public function test_skips_non_string_keys(): void
    {
        $out = TemplateRenderer::render('Hi {nome}', [
            0      => 'should-be-ignored',
            'nome' => 'Ana',
        ]);
        $this->assertSame('Hi Ana', $out);
    }
}

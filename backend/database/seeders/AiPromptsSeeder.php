<?php

namespace Database\Seeders;

use App\Models\AiPrompt;
use Illuminate\Database\Seeder;

class AiPromptsSeeder extends Seeder
{
    public function run(): void
    {
        // SMS Generation
        AiPrompt::updateOrCreate(
            ['service' => 'sms', 'version' => 'v1'],
            [
                'system_prompt' => "Você é especialista em SMS marketing brasileiro. REGRAS OBRIGATÓRIAS:\n1. SEMPRE escreva em PORTUGUÊS BRASILEIRO (pt-BR)\n2. Mensagens com no máximo 160 caracteres (incluindo link se fornecido)\n3. Se um link for fornecido, OBRIGATORIAMENTE inclua-o no final da mensagem e conte os caracteres do link no limite de 160\n4. Use linguagem direta, CTAs claros e urgência quando apropriado\n5. Evite abreviações excessivas\n6. NUNCA escreva em inglês",
                'user_template' => "Produto/Serviço: {{product}}\nPúblico-alvo: {{audience}}\nPrincipal benefício: {{benefit}}\nChamada para ação: {{cta}}\nTom: {{tone}}\nLink (DEVE ser incluído na mensagem se presente): {{link}}\nPalavras a evitar: {{avoid}}\nLimite de caracteres: {{max_chars}}\nGere exatamente {{variations}} mensagens SMS diferentes em PORTUGUÊS BRASILEIRO.\nSe o link foi fornecido, cada mensagem DEVE conter o link e os caracteres do link contam no limite de 160.\nRetorne APENAS um JSON: { \"variations\": [{\"text\":\"...\"}] }",
                'model'         => 'grok-3-mini',
                'is_active'     => true,
            ]
        );

        // SMS Analysis
        AiPrompt::updateOrCreate(
            ['service' => 'sms_analysis', 'version' => 'v1'],
            [
                'system_prompt' => "Você analisa mensagens SMS de marketing em português brasileiro. SEMPRE responda em PORTUGUÊS BRASILEIRO.",
                'user_template' => "Analise esta mensagem SMS e retorne APENAS um JSON em português:\nMensagem: \"{{content}}\"\nRetorne: {\n  \"analysis\": { \"score\": 0-100, \"char_count\": N, \"strengths\": [\"em português\"], \"improvements\": [\"em português\"] },\n  \"improved_versions\": [{\"text\":\"versão melhorada em português\", \"change\":\"descrição da mudança em português\"}]\n}\nGere exatamente 3 versões melhoradas EM PORTUGUÊS BRASILEIRO.",
                'model'         => 'grok-3-mini',
                'is_active'     => true,
            ]
        );

        // WhatsApp Generation
        AiPrompt::updateOrCreate(
            ['service' => 'whatsapp', 'version' => 'v1'],
            [
                'system_prompt' => "Você é especialista em mensagens WhatsApp Business brasileiro. REGRAS OBRIGATÓRIAS:\n1. SEMPRE escreva em PORTUGUÊS BRASILEIRO (pt-BR)\n2. Limite: 1024 caracteres (incluindo link se fornecido)\n3. Se um link for fornecido, OBRIGATORIAMENTE inclua-o na mensagem\n4. Crie mensagens conversacionais, amigáveis e persuasivas\n5. Use emojis com moderação\n6. Pode usar formatação WhatsApp (*negrito*, _itálico_, ~tachado~)\n7. NUNCA escreva em inglês",
                'user_template' => "Produto/Serviço: {{product}}\nPúblico-alvo: {{audience}}\nPrincipal benefício: {{benefit}}\nChamada para ação: {{cta}}\nTom: {{tone}}\nLink (DEVE ser incluído na mensagem se presente): {{link}}\nPalavras a evitar: {{avoid}}\nGere exatamente {{variations}} mensagens WhatsApp diferentes em PORTUGUÊS BRASILEIRO.\nSe o link foi fornecido, cada mensagem DEVE conter o link.\nRetorne APENAS um JSON: { \"variations\": [{\"text\":\"...\"}] }",
                'model'         => 'grok-3-mini',
                'is_active'     => true,
            ]
        );

        // WhatsApp Analysis
        AiPrompt::updateOrCreate(
            ['service' => 'whatsapp_analysis', 'version' => 'v1'],
            [
                'system_prompt' => "Você analisa mensagens WhatsApp Business em português brasileiro. SEMPRE responda em PORTUGUÊS BRASILEIRO.",
                'user_template' => "Analise esta mensagem WhatsApp e retorne APENAS um JSON em português:\nMensagem: \"{{content}}\"\nRetorne: {\n  \"analysis\": { \"score\": 0-100, \"strengths\": [\"em português\"], \"improvements\": [\"em português\"] },\n  \"improved_versions\": [{\"text\":\"versão em português\", \"change\":\"mudança em português\"}]\n}\nGere exatamente 3 versões melhoradas EM PORTUGUÊS BRASILEIRO.",
                'model'         => 'grok-3-mini',
                'is_active'     => true,
            ]
        );

        // Voice (channel name usado pelo generateVariations)
        AiPrompt::updateOrCreate(
            ['service' => 'voice', 'version' => 'v1'],
            [
                'system_prompt' => "Você é um especialista em marketing de voz e locuções comerciais brasileiro. REGRAS OBRIGATÓRIAS:\n1. SEMPRE escreva em PORTUGUÊS BRASILEIRO (pt-BR)\n2. Crie roteiros naturais, adequados para síntese de voz (TTS)\n3. Use frases curtas e pausas naturais (vírgulas para pausas curtas, pontos para pausas longas)\n4. Ideal: 75-150 palavras (30-60 segundos)\n5. NÃO inclua links (áudio não tem link clicável)\n6. Evite pontuação excessiva\n7. NUNCA escreva em inglês",
                'user_template' => "Produto/Serviço: {{product}}\nPúblico-alvo: {{audience}}\nPrincipal benefício: {{benefit}}\nChamada para ação: {{cta}}\nTom: {{tone}}\nPalavras a evitar: {{avoid}}\nGere exatamente {{variations}} roteiros de voz diferentes em PORTUGUÊS BRASILEIRO.\nRetorne APENAS um JSON: { \"variations\": [{\"text\":\"...\"}] }",
                'model'         => 'grok-3-mini',
                'is_active'     => true,
            ]
        );

        // Voice Script (legacy alias)
        AiPrompt::updateOrCreate(
            ['service' => 'voice_script', 'version' => 'v1'],
            [
                'system_prompt' => "Você é um especialista em marketing de voz e locuções comerciais brasileiro. REGRAS OBRIGATÓRIAS:\n1. SEMPRE escreva em PORTUGUÊS BRASILEIRO (pt-BR)\n2. Crie roteiros naturais para síntese de voz (TTS)\n3. Frases curtas com pausas naturais\n4. Ideal: 75-150 palavras (30-60 segundos)\n5. NÃO inclua links\n6. NUNCA escreva em inglês",
                'user_template' => "Produto/Serviço: {{product}}\nPúblico-alvo: {{audience}}\nPrincipal benefício: {{benefit}}\nChamada para ação: {{cta}}\nTom: {{tone}}\nPalavras a evitar: {{avoid}}\nGere exatamente {{variations}} roteiros de voz diferentes em PORTUGUÊS BRASILEIRO.\nRetorne APENAS um JSON: { \"variations\": [{\"text\":\"...\"}] }",
                'model'         => 'grok-3-mini',
                'is_active'     => true,
            ]
        );

        // Voice Analysis
        AiPrompt::updateOrCreate(
            ['service' => 'voice_analysis', 'version' => 'v1'],
            [
                'system_prompt' => "Você analisa roteiros de torpedo de voz em português brasileiro. SEMPRE responda em PORTUGUÊS BRASILEIRO.",
                'user_template' => "Analise este roteiro de voz e retorne APENAS um JSON em português:\nRoteiro: \"{{content}}\"\nRetorne: {\n  \"analysis\": { \"score\": 0-100, \"strengths\": [\"em português\"], \"improvements\": [\"em português\"] },\n  \"improved_versions\": [{\"text\":\"roteiro em português\", \"change\":\"mudança em português\"}]\n}\nGere exatamente 3 versões melhoradas EM PORTUGUÊS BRASILEIRO.",
                'model'         => 'grok-3-mini',
                'is_active'     => true,
            ]
        );

        // Email Generation
        AiPrompt::updateOrCreate(
            ['service' => 'email', 'version' => 'v1'],
            [
                'system_prompt' => "Você é especialista em email marketing brasileiro. REGRAS OBRIGATÓRIAS:\n1. SEMPRE escreva em PORTUGUÊS BRASILEIRO (pt-BR)\n2. Crie emails com assunto atraente e corpo persuasivo\n3. Se um link for fornecido, OBRIGATORIAMENTE inclua-o no corpo do email como CTA\n4. Evite spam triggers (grátis, urgente, clique aqui)\n5. Use CTAs claros e diretos\n6. NUNCA escreva em inglês",
                'user_template' => "Produto/Serviço: {{product}}\nPúblico-alvo: {{audience}}\nPrincipal benefício: {{benefit}}\nChamada para ação: {{cta}}\nTom: {{tone}}\nLink (DEVE ser incluído no email se presente): {{link}}\nPalavras a evitar: {{avoid}}\nGere exatamente {{variations}} versões diferentes em PORTUGUÊS BRASILEIRO.\nSe o link foi fornecido, cada email DEVE conter o link como botão ou CTA.\nRetorne APENAS um JSON: { \"variations\": [{\"subject\":\"...\",\"text\":\"...\"}] }",
                'model'         => 'grok-3-mini',
                'is_active'     => true,
            ]
        );

        // Email Analysis
        AiPrompt::updateOrCreate(
            ['service' => 'email_analysis', 'version' => 'v1'],
            [
                'system_prompt' => "Você analisa emails de marketing em português brasileiro. SEMPRE responda em PORTUGUÊS BRASILEIRO.",
                'user_template' => "Analise este email e retorne APENAS um JSON em português:\nConteúdo: \"{{content}}\"\nRetorne: {\n  \"analysis\": { \"score\": 0-100, \"strengths\": [\"em português\"], \"improvements\": [\"em português\"], \"spam_risk\": \"low|medium|high\" },\n  \"improved_versions\": [{\"text\":\"versão em português\", \"change\":\"mudança em português\"}]\n}\nGere exatamente 3 versões melhoradas EM PORTUGUÊS BRASILEIRO.",
                'model'         => 'grok-3-mini',
                'is_active'     => true,
            ]
        );
    }
}

<?php

namespace App\Http\Controllers\API\V1\Messaging;

use App\Exceptions\Billing\BillingBlockedException;
use App\Exceptions\Billing\BillingSuspendedException;
use App\Exceptions\Billing\InsufficientFundsException;
use App\Exceptions\Messaging\IdempotencyKeyReuseException;
use App\Exceptions\Messaging\QuietHoursException;
use App\Exceptions\Messaging\RecipientOptedOutException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\SendEmailRequest;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateRenderer;
use Illuminate\Http\JsonResponse;

class EmailController extends Controller
{
    public function __construct(private MessagingService $messaging) {}

    public function store(SendEmailRequest $request): JsonResponse
    {
        $payload = $request->validated();

        // Render template variables BEFORE sanitization so any HTML/CRLF
        // smuggled through variable values still gets stripped by the
        // downstream sanitizers (defense-in-depth against XSS via variables
        // and CWE-93 SMTP header injection via subject).
        if (!empty($payload['variables']) && is_array($payload['variables'])) {
            $payload['subject'] = TemplateRenderer::render($payload['subject'], $payload['variables']);
            $payload['content'] = TemplateRenderer::render($payload['content'], $payload['variables']);
            // Re-apply CRLF strip on the rendered subject — a variable value
            // could carry \r\n that the original prepareForValidation() pass
            // wouldn't have seen.
            $payload['subject'] = str_replace(["\r", "\n"], '', $payload['subject']);
        }
        // Note: `variables` stays on the payload so MessagingService persists it.

        $payload['content'] = $this->sanitizeHtml($payload['content']);

        try {
            $dispatch = $this->messaging->dispatch(
                $request->user()->tenant,
                $request->user(),
                'email',
                $payload,
                $request->header('Idempotency-Key'),
                $request->header('X-Quiet-Hours-Strategy', 'reject')
            );
        } catch (RecipientOptedOutException) {
            return response()->json(['error' => 'RECIPIENT_OPTED_OUT'], 422);
        } catch (QuietHoursException) {
            return response()->json(['error' => 'QUIET_HOURS'], 422);
        } catch (InsufficientFundsException $e) {
            return response()->json([
                'error'     => 'INSUFFICIENT_FUNDS',
                'required'  => $e->required_cents,
                'available' => $e->available_cents,
            ], 402);
        } catch (BillingSuspendedException) {
            return response()->json(['error' => 'BILLING_SUSPENDED'], 402);
        } catch (BillingBlockedException) {
            return response()->json(['error' => 'BILLING_BLOCKED'], 402);
        } catch (IdempotencyKeyReuseException) {
            return response()->json(['error' => 'IDEMPOTENCY_KEY_REUSE'], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'INVALID_INPUT', 'detail' => $e->getMessage()], 422);
        }

        return response()->json([
            'dispatch_id'    => $dispatch->id,
            'status'         => $dispatch->status,
            'cost_cents'     => (int) $dispatch->cost_cents,
            'sale_cents'     => (int) $dispatch->sale_cents,
            'reserved_cents' => (int) $dispatch->sale_cents,
            '_links'         => ['status' => url("/api/v1/messaging/dispatches/{$dispatch->id}")],
        ], 202);
    }

    private function sanitizeHtml(string $html): string
    {
        // Defense-in-depth sanitization. Replace with HTMLPurifier in production.
        // 1. Strip dangerous tags entirely (with content).
        $clean = preg_replace('#<(script|iframe|object|embed|style|svg)[^>]*>.*?</\1>#is', '', $html);
        // 2. Strip self-closing / void dangerous tags.
        $clean = preg_replace('#<(script|iframe|object|embed|style|link|meta|svg)[^>]*/?>#i', '', $clean);
        // 3. Strip event handler attributes (quoted double, quoted single, AND unquoted).
        $clean = preg_replace('#\son\w+\s*=\s*"[^"]*"#i',   '', $clean);
        $clean = preg_replace("#\son\w+\s*=\s*'[^']*'#i",   '', $clean);
        $clean = preg_replace('#\son\w+\s*=\s*[^\s>]+#i',   '', $clean);
        // 4. Neutralize javascript: URIs in href/src.
        $clean = preg_replace('#\b(href|src)\s*=\s*"javascript:[^"]*"#i', '$1="#"', $clean);
        $clean = preg_replace("#\b(href|src)\s*=\s*'javascript:[^']*'#i", '$1="#"', $clean);
        $clean = preg_replace('#\b(href|src)\s*=\s*javascript:[^\s>]+#i', '$1="#"', $clean);
        return $clean;
    }
}

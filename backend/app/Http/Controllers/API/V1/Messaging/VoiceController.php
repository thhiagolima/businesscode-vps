<?php

namespace App\Http\Controllers\API\V1\Messaging;

use App\Exceptions\Billing\BillingBlockedException;
use App\Exceptions\Billing\BillingSuspendedException;
use App\Exceptions\Billing\InsufficientFundsException;
use App\Exceptions\Messaging\IdempotencyKeyReuseException;
use App\Exceptions\Messaging\QuietHoursException;
use App\Exceptions\Messaging\RecipientOptedOutException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\SendVoiceRequest;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateRenderer;
use Illuminate\Http\JsonResponse;

class VoiceController extends Controller
{
    public function __construct(private MessagingService $messaging) {}

    public function store(SendVoiceRequest $request): JsonResponse
    {
        $payload = $request->validated();
        if (!empty($payload['variables']) && is_array($payload['variables']) && !empty($payload['content'])) {
            $payload['content'] = TemplateRenderer::render($payload['content'], $payload['variables']);
        }
        // Note: `variables` stays on the payload so MessagingService persists it.

        try {
            $dispatch = $this->messaging->dispatch(
                $request->user()->tenant,
                $request->user(),
                'voice',
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
}

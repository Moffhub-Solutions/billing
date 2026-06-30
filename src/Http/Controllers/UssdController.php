<?php

declare(strict_types=1);

namespace Moffhub\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Moffhub\Billing\Services\UssdMenuBuilder;

class UssdController extends Controller
{
    public function __construct(
        protected UssdMenuBuilder $menuBuilder,
    ) {}

    /**
     * Handle incoming USSD callback from Africa's Talking or similar gateway.
     *
     * Expects: sessionId, phoneNumber, serviceCode, text
     * Returns: plain text prefixed with CON (continue) or END (terminal).
     */
    public function handle(Request $request): Response
    {
        $sessionId = $request->string('sessionId', '')->toString();
        $phoneNumber = $request->string('phoneNumber', '')->toString();
        $serviceCode = $request->string('serviceCode', '')->toString();
        $text = $request->string('text', '')->toString();

        Log::debug('USSD request received', [
            'session_id' => $sessionId,
            'phone' => $this->maskPhone($phoneNumber),
            'service_code' => $serviceCode,
            'text' => $text,
        ]);

        try {
            $result = $this->menuBuilder->handle($sessionId, $phoneNumber, $text);

            $prefix = $result['is_terminal'] ? 'END' : 'CON';
            $body = $prefix.' '.$result['response'];

            return response($body, 200)
                ->header('Content-Type', 'text/plain');
        } catch (\Throwable $e) {
            Log::error('USSD processing error', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return response('END An error occurred. Please try again later.', 200)
                ->header('Content-Type', 'text/plain');
        }
    }

    /**
     * Mask a phone number for logging (PII): keep only the last 3 digits.
     */
    private function maskPhone(string $phone): string
    {
        if ($phone === '') {
            return '';
        }

        $tail = substr($phone, -3);

        return str_repeat('*', max(0, strlen($phone) - 3)).$tail;
    }
}

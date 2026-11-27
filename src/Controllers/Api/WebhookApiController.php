<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Services\RazorpayService;

final class WebhookApiController extends ApiBaseController
{
    public function razorpay(Request $request): void
    {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || $rawBody === '') {
            $rawBody = json_encode($request->all()) ?: '';
        }

        $signature = (string)($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? $request->getHeader('x-razorpay-signature') ?? '');

        $razorpay = new RazorpayService();
        try {
            $result = $razorpay->handleWebhook($rawBody, $signature);
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 400, 'INVALID_WEBHOOK');
            return;
        } catch (\Throwable $e) {
            $this->jsonError('Webhook processing failed: ' . $e->getMessage(), 500, 'SERVER_ERROR');
            return;
        }

        $this->jsonSuccess($result, 'Webhook processed.');
    }
}

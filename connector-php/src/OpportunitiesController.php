<?php

declare(strict_types=1);

namespace SuiteSidecar;

use SuiteSidecar\Http\Response;
use SuiteSidecar\SuiteCrm\CrmAdapterInterface;
use SuiteSidecar\SuiteCrm\SuiteCrmAuthException;
use SuiteSidecar\SuiteCrm\SuiteCrmBadResponseException;
use SuiteSidecar\SuiteCrm\SuiteCrmException;
use SuiteSidecar\SuiteCrm\SuiteCrmHttpException;

final class OpportunitiesController
{
    private const ALLOWED_PERSON_MODULES = ['Contacts', 'Leads'];

    public function __construct(
        private readonly CrmAdapterInterface $adapter
    ) {
    }

    public function byContext(): void
    {
        $personModule = trim((string) ($_GET['personModule'] ?? ''));
        $personId = trim((string) ($_GET['personId'] ?? ''));
        $accountId = trim((string) ($_GET['accountId'] ?? ''));
        $limit = $this->toPositiveIntOrNull($_GET['limit'] ?? null) ?? 5;

        if ($personModule !== '' && !in_array($personModule, self::ALLOWED_PERSON_MODULES, true)) {
            Response::error('bad_request', 'Invalid personModule', 400);
            return;
        }

        if ($personId === '' && $accountId === '') {
            Response::error('bad_request', 'Provide personId or accountId', 400);
            return;
        }

        try {
            $payload = $this->adapter->listOpportunities([
                'personModule' => $personModule,
                'personId' => $personId,
                'accountId' => $accountId,
                'limit' => $limit,
            ]);
            Response::json($payload, 200);
        } catch (SuiteCrmAuthException $e) {
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM auth failed during opportunities lookup');
            $statusCode = $e->getStatusCode();
            if (!in_array($statusCode, [401, 502], true)) {
                $statusCode = 401;
            }
            Response::error('suitecrm_auth_failed', 'SuiteCRM authentication failed', $statusCode);
        } catch (SuiteCrmBadResponseException) {
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM returned invalid opportunities payload');
            Response::error('suitecrm_bad_response', 'SuiteCRM returned an invalid response', 502);
        } catch (SuiteCrmHttpException $e) {
            error_log(
                '[requestId=' . Response::requestId() . '] SuiteCRM HTTP error'
                . ' endpoint=' . $e->getEndpoint()
                . ' status=' . $e->getStatus()
            );
            if (in_array($e->getStatus(), [401, 403], true)) {
                Response::error('suitecrm_auth_failed', 'SuiteCRM authentication failed', 401);
                return;
            }
            Response::error('suitecrm_unreachable', 'SuiteCRM is temporarily unreachable', 502);
        } catch (SuiteCrmException) {
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM request failed during opportunities lookup');
            Response::error('suitecrm_unreachable', 'SuiteCRM is temporarily unreachable', 502);
        }
    }

    private function toPositiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_float($value)) {
            $parsed = (int) round($value);
            return $parsed > 0 && abs($value - $parsed) < 0.0001 ? $parsed : null;
        }

        if (is_string($value) && trim($value) !== '') {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            return is_int($parsed) && $parsed > 0 ? $parsed : null;
        }

        return null;
    }
}

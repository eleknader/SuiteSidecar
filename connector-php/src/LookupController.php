<?php

declare(strict_types=1);

namespace SuiteSidecar;

use SuiteSidecar\Http\Response;
use SuiteSidecar\SuiteCrm\CrmAdapterInterface;
use SuiteSidecar\SuiteCrm\SuiteCrmAuthException;
use SuiteSidecar\SuiteCrm\SuiteCrmBadResponseException;
use SuiteSidecar\SuiteCrm\SuiteCrmException;
use SuiteSidecar\SuiteCrm\SuiteCrmHttpException;

final class LookupController
{
    public function __construct(
        private readonly CrmAdapterInterface $adapter
    ) {
    }

    public function byEmail(): void
    {
        $email = isset($_GET['email']) ? trim((string)$_GET['email']) : '';
        if ($email === '') {
            Response::error('bad_request', 'Missing required query parameter: email', 400);
            return;
        }

        // Parse include=account,timeline (optional)
        $includeRaw = isset($_GET['include']) ? (string)$_GET['include'] : '';
        $include = array_values(array_filter(array_map('trim', explode(',', $includeRaw))));

        try {
            $payload = $this->adapter->lookupByEmail($email, $include);
            Response::json($payload, 200);
        } catch (SuiteCrmAuthException) {
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM auth failed during lookup');
            Response::error('suitecrm_auth_failed', 'SuiteCRM authentication failed', 502);
        } catch (SuiteCrmBadResponseException) {
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM returned invalid response payload');
            Response::error('suitecrm_bad_response', 'SuiteCRM returned an invalid response', 502);
        } catch (SuiteCrmHttpException $e) {
            error_log(
                '[requestId=' . Response::requestId() . '] SuiteCRM HTTP error'
                . ' endpoint=' . $e->getEndpoint()
                . ' status=' . $e->getStatus()
            );
            Response::error('suitecrm_unreachable', 'SuiteCRM is temporarily unreachable', 502);
        } catch (SuiteCrmException) {
            error_log('[requestId=' . Response::requestId() . '] SuiteCRM request failed');
            Response::error('suitecrm_unreachable', 'SuiteCRM is temporarily unreachable', 502);
        }
    }
}

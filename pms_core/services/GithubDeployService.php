<?php
declare(strict_types=1);

/**
 * Triggers the Hostinger GitHub Actions workflow and reads the latest run.
 */
class GithubDeployService
{
    public static function isConfigured(): bool
    {
        return self::token() !== '';
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function triggerDeploy(): array
    {
        $token = self::token();
        if ($token === '') {
            return ['ok' => false, 'message' => 'GITHUB_DEPLOY_TOKEN is not set in .env'];
        }

        $repo = self::repo();
        $workflow = rawurlencode(self::workflowFile());
        $ref = self::ref();
        $url = "https://api.github.com/repos/{$repo}/actions/workflows/{$workflow}/dispatches";

        $result = self::request('POST', $url, $token, ['ref' => $ref]);
        $code = (int)($result['code'] ?? 0);

        if ($code === 204) {
            return ['ok' => true, 'message' => 'Hostinger deploy started from branch ' . $ref . '. Wait a minute, then refresh this page for status. After it succeeds, run /admin/run_migration.php if this release added schema files.'];
        }

        $err = self::apiError($result);
        return ['ok' => false, 'message' => $err !== '' ? $err : "GitHub returned HTTP {$code}"];
    }

    /**
     * @return array{status: string, conclusion: ?string, html_url: string, created_at: string, head_sha: string}|null
     */
    public static function latestRun(): ?array
    {
        $token = self::token();
        if ($token === '') {
            return null;
        }

        $repo = self::repo();
        $workflow = rawurlencode(self::workflowFile());
        $url = "https://api.github.com/repos/{$repo}/actions/workflows/{$workflow}/runs?per_page=1";
        $result = self::request('GET', $url, $token, null);
        $body = $result['body'] ?? [];
        $run = $body['workflow_runs'][0] ?? null;
        if (!is_array($run)) {
            return null;
        }

        return [
            'status' => (string)($run['status'] ?? ''),
            'conclusion' => isset($run['conclusion']) ? (string)$run['conclusion'] : null,
            'html_url' => (string)($run['html_url'] ?? ''),
            'created_at' => (string)($run['created_at'] ?? ''),
            'head_sha' => substr((string)($run['head_sha'] ?? ''), 0, 7),
        ];
    }

    public static function repo(): string
    {
        $repo = trim((string)(getenv('GITHUB_REPO') ?: ($_ENV['GITHUB_REPO'] ?? 'akhillaka/micropms')));
        return $repo !== '' ? $repo : 'akhillaka/micropms';
    }

    private static function token(): string
    {
        return trim((string)(getenv('GITHUB_DEPLOY_TOKEN') ?: ($_ENV['GITHUB_DEPLOY_TOKEN'] ?? '')));
    }

    private static function workflowFile(): string
    {
        $file = trim((string)(getenv('GITHUB_DEPLOY_WORKFLOW') ?: ($_ENV['GITHUB_DEPLOY_WORKFLOW'] ?? 'deploy-hostinger.yml')));
        return $file !== '' ? $file : 'deploy-hostinger.yml';
    }

    private static function ref(): string
    {
        $ref = trim((string)(getenv('GITHUB_DEPLOY_REF') ?: ($_ENV['GITHUB_DEPLOY_REF'] ?? 'main')));
        return $ref !== '' ? $ref : 'main';
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{code: int, body: array}
     */
    private static function request(string $method, string $url, string $token, ?array $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['code' => 0, 'body' => ['message' => 'Failed to start HTTP request']];
        }

        $headers = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: MicroPMS-SaaS-Deploy',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        }

        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['code' => 0, 'body' => ['message' => $err !== '' ? $err : 'Could not reach GitHub']];
        }
        curl_close($ch);

        $decoded = json_decode((string)$raw, true);
        return ['code' => $code, 'body' => is_array($decoded) ? $decoded : []];
    }

    /**
     * @param array{code: int, body: array} $result
     */
    private static function apiError(array $result): string
    {
        $body = $result['body'];
        $msg = trim((string)($body['message'] ?? ''));
        if ($msg !== '') {
            return $msg;
        }
        return '';
    }
}

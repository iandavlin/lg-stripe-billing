<?php

declare(strict_types=1);

namespace LGSB\Http\Controllers;

use LGSB\Contracts\SettingsStore;
use LGSB\Domain\Repositories\AffiliateRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AffiliateController
{
    public function __construct(
        private readonly AffiliateRepository $affiliates,
        private readonly SettingsStore       $settings,
    ) {}

    /** GET /v1/affiliates */
    public function list(Request $request, Response $response): Response
    {
        if (!$this->authorized($request)) {
            return self::json($response, ['error' => 'Unauthorized.'], 401);
        }
        return self::json($response, $this->affiliates->listWithCounts());
    }

    /** POST /v1/affiliates  body: {slug, label?} */
    public function create(Request $request, Response $response): Response
    {
        if (!$this->authorized($request)) {
            return self::json($response, ['error' => 'Unauthorized.'], 401);
        }
        $body  = (array) $request->getParsedBody();
        $slug  = trim(preg_replace('/[^a-z0-9_-]/i', '-', (string) ($body['slug'] ?? '')) ?? '');
        $label = trim((string) ($body['label'] ?? ''));

        if ($slug === '') {
            return self::json($response, ['error' => 'slug is required.'], 400);
        }
        if ($label === '') {
            $label = $slug;
        }

        try {
            $row = $this->affiliates->create($slug, $label);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return self::json($response, ['error' => "Slug '{$slug}' already exists."], 409);
            }
            return self::json($response, ['error' => $e->getMessage()], 500);
        }

        return self::json($response, $row, 201);
    }

    /**
     * POST /v1/affiliate-click  body: {ref: "slug"}
     * Public — no auth. Fire-and-forget from the browser on landing.
     */
    public function click(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $slug = trim((string) ($body['ref'] ?? ''));
        if ($slug !== '') {
            $this->affiliates->recordClick($slug);
        }
        return self::json($response, ['ok' => true]);
    }

    /** GET /v1/affiliates/{id}/conversions */
    public function conversions(Request $request, Response $response, array $args): Response
    {
        if (!$this->authorized($request)) {
            return self::json($response, ['error' => 'Unauthorized.'], 401);
        }
        $id   = (int) ($args['id'] ?? 0);
        $rows = $this->affiliates->conversionsForAffiliate($id);
        return self::json($response, $rows);
    }

    private function authorized(Request $request): bool
    {
        $token    = $request->getHeaderLine('X-LGMS-Token');
        $expected = $this->settings->getSyncSharedSecret();
        return $expected !== '' && hash_equals($expected, $token);
    }

    private static function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}

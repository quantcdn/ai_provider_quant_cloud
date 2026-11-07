<?php

declare(strict_types=1);

namespace Drupal\ai_provider_quant_cloud\Client;

use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\ResponseInterface;

/**
 * Retries an API request once after a 401 with a newer stored token.
 *
 * A long-running process (for example a queue worker) can send a token
 * that a peer process has since rotated. The using class must have the
 * $httpClient and $authService properties.
 */
trait AuthRetryTrait {

  /**
   * Send a request; after a 401, retry once with a newer stored token.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   When the request fails and no retry applies, or the retry fails.
   */
  protected function sendWithAuthRetry(string $method, string $url, array $options): ResponseInterface {
    try {
      return $this->httpClient->request($method, $url, $options);
    }
    catch (ClientException $e) {
      $retry_options = $this->getRetryOptionsAfterUnauthorized($e, $options);
      if ($retry_options === NULL) {
        throw $e;
      }
      return $this->httpClient->request($method, $url, $retry_options);
    }
  }

  /**
   * The request options for a retry after a 401, or NULL for no retry.
   */
  protected function getRetryOptionsAfterUnauthorized(ClientException $e, array $options): ?array {
    if ($e->getResponse()->getStatusCode() !== 401) {
      return NULL;
    }
    $header = (string) ($options['headers']['Authorization'] ?? '');
    $used_token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
    $token = $used_token !== '' ? $this->authService->getTokenAfterUnauthorized($used_token) : NULL;
    if ($token === NULL) {
      return NULL;
    }
    $options['headers']['Authorization'] = 'Bearer ' . $token;
    return $options;
  }

}

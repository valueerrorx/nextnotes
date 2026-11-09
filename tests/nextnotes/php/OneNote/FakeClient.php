<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IPromise;
use OCP\Http\Client\IResponse;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

// IClient für FakeHttp; nur request() und die Kurzformen
final class FakeClient implements IClient {
	public function __construct(
		private FakeHttp $http,
	) {
	}

	public function request(string $method, string $uri, array $options = []): IResponse {
		return $this->http->handle($method, $uri, $options);
	}

	public function get(string $uri, array $options = []): IResponse {
		return $this->request('GET', $uri, $options);
	}

	public function head(string $uri, array $options = []): IResponse {
		return $this->request('HEAD', $uri, $options);
	}

	public function post(string $uri, array $options = []): IResponse {
		return $this->request('POST', $uri, $options);
	}

	public function put(string $uri, array $options = []): IResponse {
		return $this->request('PUT', $uri, $options);
	}

	public function patch(string $uri, array $options = []): IResponse {
		return $this->request('PATCH', $uri, $options);
	}

	public function delete(string $uri, array $options = []): IResponse {
		return $this->request('DELETE', $uri, $options);
	}

	public function options(string $uri, array $options = []): IResponse {
		return $this->request('OPTIONS', $uri, $options);
	}

	public function getResponseFromThrowable(\Throwable $e): IResponse {
		throw new \LogicException('not used');
	}

	public function sendRequest(RequestInterface $request): ResponseInterface {
		throw new \LogicException('not used');
	}

	public function getAsync(string $uri, array $options = []): IPromise {
		throw new \LogicException('not used');
	}

	public function headAsync(string $uri, array $options = []): IPromise {
		throw new \LogicException('not used');
	}

	public function postAsync(string $uri, array $options = []): IPromise {
		throw new \LogicException('not used');
	}

	public function putAsync(string $uri, array $options = []): IPromise {
		throw new \LogicException('not used');
	}

	public function deleteAsync(string $uri, array $options = []): IPromise {
		throw new \LogicException('not used');
	}

	public function optionsAsync(string $uri, array $options = []): IPromise {
		throw new \LogicException('not used');
	}
}

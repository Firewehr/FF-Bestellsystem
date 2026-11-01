/**
 * Gemeinsamer HTTP-Client für die Pilot-Oberflächen.
 * Schreibende Anfragen werden bewusst nicht automatisch wiederholt:
 * Der jeweilige Fachablauf entscheidet anhand eines Idempotenzschlüssels,
 * ob eine Wiederholung sicher ist.
 */

export class PilotApiError extends Error {
  constructor(message, status = 0, code = 'request_failed', requestId = '') {
    super(message);
    this.name = 'PilotApiError';
    this.status = status;
    this.code = code;
    this.requestId = requestId;
  }
}

function requestId() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function createPilotApi({ getCsrfToken, setCsrfToken, onUnauthorized } = {}) {
  return {
    async request(endpoint, options = {}) {
      const {
        body,
        headers: providedHeaders = {},
        method = 'GET',
        timeout = 15000,
        ...fetchOptions
      } = options;
      const headers = new Headers(providedHeaders);
      headers.set('Accept', 'application/json');
      headers.set('X-Request-ID', requestId());

      const csrfToken = typeof getCsrfToken === 'function' ? getCsrfToken() : '';
      if (csrfToken) {
        headers.set('X-CSRF-Token', csrfToken);
      }

      let requestBody = body;
      if (body && typeof body === 'object' && !(body instanceof FormData)) {
        headers.set('Content-Type', 'application/json');
        requestBody = JSON.stringify(body);
      }

      const controller = new AbortController();
      const timer = window.setTimeout(() => controller.abort(), timeout);

      try {
        const response = await fetch(endpoint, {
          credentials: 'same-origin',
          ...fetchOptions,
          method,
          headers,
          body: requestBody,
          signal: controller.signal,
        });
        const rawBody = await response.text();
        let data;
        try {
          data = rawBody === '' ? {} : JSON.parse(rawBody);
        } catch (error) {
          throw new PilotApiError(
            'Ungültige Server-Antwort.',
            response.status,
            'invalid_json',
            response.headers.get('X-Request-ID') || ''
          );
        }

        if (data.csrf_token && typeof setCsrfToken === 'function') {
          setCsrfToken(data.csrf_token);
        }
        if (response.status === 401 && typeof onUnauthorized === 'function') {
          onUnauthorized();
        }
        if (!response.ok || data.ok === false) {
          throw new PilotApiError(
            data.message || data.error || `HTTP-Fehler ${response.status}`,
            response.status,
            data.error || 'request_failed',
            data.request_id || response.headers.get('X-Request-ID') || ''
          );
        }

        return data;
      } catch (error) {
        if (error.name === 'AbortError') {
          throw new PilotApiError('Die Serveranfrage hat zu lange gedauert.', 0, 'timeout');
        }
        throw error;
      } finally {
        window.clearTimeout(timer);
      }
    },
  };
}

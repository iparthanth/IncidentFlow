import { createPublicKey, type KeyObject } from 'node:crypto';
import { jwtVerify, errors as joseErrors } from 'jose';
import type { Config } from '../config.js';
import { TicketClaimsSchema, type AuthenticatedPrincipal } from '../types.js';
import { authFailures } from '../metrics.js';

export class AuthError extends Error {
  constructor(
    message: string,
    readonly reason:
      | 'missing'
      | 'malformed'
      | 'expired'
      | 'invalid_signature'
      | 'wrong_audience'
      | 'invalid_claims',
  ) {
    super(message);
    this.name = 'AuthError';
  }
}

/**
 * Verifies realtime tickets minted by the Laravel API.
 *
 * Only the *public* half of the RS256 key pair lives in this process, so a
 * compromise of the realtime tier cannot forge a token. The audience check is
 * what stops a stolen REST access token (aud: incidentflow-api) from being
 * replayed here as a stream ticket — important because EventSource forces the
 * credential into a query string, where it is far more likely to be logged.
 */
export class TicketVerifier {
  private readonly key: KeyObject;

  constructor(private readonly config: Pick<Config,
    'jwtPublicKeyPem' | 'JWT_ALGORITHM' | 'JWT_ISSUER' | 'JWT_AUDIENCE' | 'JWT_CLOCK_TOLERANCE_SECONDS'>) {
    try {
      this.key = createPublicKey(config.jwtPublicKeyPem);
    } catch (cause) {
      throw new Error(`Failed to parse JWT public key: ${(cause as Error).message}`);
    }
  }

  async verify(token: string): Promise<AuthenticatedPrincipal> {
    if (!token || token.trim() === '') {
      authFailures.inc({ reason: 'missing' });
      throw new AuthError('No credential supplied', 'missing');
    }

    let payload: Record<string, unknown>;
    try {
      const result = await jwtVerify(token, this.key, {
        algorithms: [this.config.JWT_ALGORITHM],
        issuer: this.config.JWT_ISSUER,
        audience: this.config.JWT_AUDIENCE,
        clockTolerance: this.config.JWT_CLOCK_TOLERANCE_SECONDS,
      });
      payload = result.payload as Record<string, unknown>;
    } catch (cause) {
      const reason = classify(cause);
      authFailures.inc({ reason });
      throw new AuthError(describe(reason), reason);
    }

    const claims = TicketClaimsSchema.safeParse(payload);
    if (!claims.success) {
      authFailures.inc({ reason: 'invalid_claims' });
      throw new AuthError('Ticket is missing required claims', 'invalid_claims');
    }

    return {
      userId: claims.data.sub,
      organizationId: claims.data.org_id,
      role: claims.data.role,
      name: claims.data.name,
      tokenId: claims.data.jti,
      expiresAt: claims.data.exp,
    };
  }
}

function classify(cause: unknown): AuthError['reason'] {
  if (cause instanceof joseErrors.JWTExpired) return 'expired';
  if (cause instanceof joseErrors.JWSSignatureVerificationFailed) return 'invalid_signature';

  if (cause instanceof joseErrors.JWTClaimValidationFailed) {
    // jose reports every claim failure the same way. Separating the audience
    // case is worth the two lines: `wrong_audience` says someone replayed a
    // REST access token as a stream ticket, which is a specific and
    // actionable thing to see in a log. `invalid_claims` says nothing.
    return cause.claim === 'aud' ? 'wrong_audience' : 'invalid_claims';
  }
  if (cause instanceof joseErrors.JWSInvalid || cause instanceof joseErrors.JWTInvalid) return 'malformed';
  return 'malformed';
}

function describe(reason: AuthError['reason']): string {
  switch (reason) {
    case 'expired':
      return 'Ticket has expired';
    case 'invalid_signature':
      return 'Ticket signature is not valid';
    case 'wrong_audience':
      return 'Ticket was not issued for the realtime service';
    case 'invalid_claims':
      return 'Ticket claims failed validation';
    default:
      return 'Ticket is malformed';
  }
}

/**
 * Extracts a credential from the places a browser can actually put one.
 *
 * EventSource cannot set headers, so SSE clients pass `?ticket=`. Fetch-based
 * SSE clients and WebSocket clients that can set headers should prefer
 * `Authorization: Bearer` — it keeps the credential out of access logs.
 */
export function extractCredential(
  headers: Record<string, string | string[] | undefined>,
  query: Record<string, unknown>,
): string | null {
  const auth = headers['authorization'];
  const header = Array.isArray(auth) ? auth[0] : auth;
  if (header && /^Bearer\s+/i.test(header)) {
    return header.replace(/^Bearer\s+/i, '').trim();
  }

  const ticket = query['ticket'];
  if (typeof ticket === 'string' && ticket.trim() !== '') {
    return ticket.trim();
  }

  const protocolHeader = headers['sec-websocket-protocol'];
  const protocols = Array.isArray(protocolHeader) ? protocolHeader.join(',') : protocolHeader;
  if (protocols) {
    // Convention: client sends ["incidentflow.v1", "ticket.<jwt>"].
    const match = protocols
      .split(',')
      .map((part) => part.trim())
      .find((part) => part.startsWith('ticket.'));
    if (match) return match.slice('ticket.'.length);
  }

  return null;
}

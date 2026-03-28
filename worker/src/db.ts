import { Pool, type PoolClient, type QueryResult } from 'pg';

export interface WorkerEnv {
  DATABASE_URL: string;
  STRIPE_SECRET_KEY?: string;
  STRIPE_WEBHOOK_SECRET?: string;
  LICENSE_HMAC_SECRET: string;
  PRODUCT_SLUG?: string;
}

let pool: Pool | null = null;
let cachedDatabaseUrl = '';

function getPool(env: WorkerEnv): Pool {
  if (!env.DATABASE_URL) {
    throw new Error('DATABASE_URL is required');
  }

  if (!pool || cachedDatabaseUrl !== env.DATABASE_URL) {
    cachedDatabaseUrl = env.DATABASE_URL;
    pool = new Pool({
      connectionString: env.DATABASE_URL,
      ssl: { rejectUnauthorized: false },
      max: 6,
      idleTimeoutMillis: 15000,
      connectionTimeoutMillis: 8000,
    });
  }

  return pool;
}

export async function query<T = unknown>(
  env: WorkerEnv,
  sql: string,
  params: unknown[] = [],
): Promise<QueryResult<T>> {
  return getPool(env).query<T>(sql, params);
}

export async function withTransaction<T>(
  env: WorkerEnv,
  runner: (client: PoolClient) => Promise<T>,
): Promise<T> {
  const client = await getPool(env).connect();

  try {
    await client.query('BEGIN');
    const result = await runner(client);
    await client.query('COMMIT');
    return result;
  } catch (error) {
    await client.query('ROLLBACK');
    throw error;
  } finally {
    client.release();
  }
}

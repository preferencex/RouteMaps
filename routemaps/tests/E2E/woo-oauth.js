import { createHmac, randomBytes } from 'node:crypto';

const encode = (value) => encodeURIComponent(String(value))
  .replace(/[!'()*]/g, (character) => `%${character.charCodeAt(0).toString(16).toUpperCase()}`);

const normalizedBaseUrl = (url) => {
  const port = url.port && !((url.protocol === 'http:' && url.port === '80') || (url.protocol === 'https:' && url.port === '443'))
    ? `:${url.port}`
    : '';
  return `${url.protocol}//${url.hostname}${port}${url.pathname}`;
};

export const createWooRequestAuth = (method, baseUrl, path, consumerKey, consumerSecret, options = {}) => {
  const endpoint = new URL(`/wp-json/wc/v3/${String(path).replace(/^\//, '')}`, baseUrl);
  if (endpoint.protocol === 'https:') {
    return {
      url: endpoint.toString(),
      httpCredentials: { username: consumerKey, password: consumerSecret },
    };
  }

  const oauth = {
    oauth_consumer_key: consumerKey,
    oauth_nonce: options.nonce || randomBytes(12).toString('hex'),
    oauth_signature_method: 'HMAC-SHA256',
    oauth_timestamp: String(options.timestamp || Math.floor(Date.now() / 1000)),
    oauth_version: '1.0',
  };

  const parameters = [];
  endpoint.searchParams.forEach((value, key) => parameters.push([key, value]));
  Object.entries(oauth).forEach(([key, value]) => parameters.push([key, value]));
  parameters.sort((left, right) => {
    const keyOrder = encode(left[0]).localeCompare(encode(right[0]));
    return 0 !== keyOrder ? keyOrder : encode(left[1]).localeCompare(encode(right[1]));
  });
  const parameterString = parameters.map(([key, value]) => `${encode(key)}=${encode(value)}`).join('&');
  const baseString = [String(method).toUpperCase(), encode(normalizedBaseUrl(endpoint)), encode(parameterString)].join('&');
  const signingKey = `${encode(consumerSecret)}&`;
  oauth.oauth_signature = createHmac('sha256', signingKey).update(baseString).digest('base64');

  Object.entries(oauth).forEach(([key, value]) => endpoint.searchParams.set(key, value));
  return { url: endpoint.toString(), httpCredentials: undefined };
};

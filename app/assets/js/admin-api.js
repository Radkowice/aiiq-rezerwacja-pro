export async function fetchBlockedData() {
  const res = await fetch('/api/booking/blocked.php', {
    cache: 'no-store'
  });

  if (!res.ok) {
    throw new Error('B³¹d pobierania blocked.php');
  }

  return await res.json();
}

let blockedCache = null;
let blockedCacheTime = 0;
const CACHE_TTL = 5000; // 5 sekund

export async function fetchBlockedData() {
  const now = Date.now();

  if (blockedCache && (now - blockedCacheTime < CACHE_TTL)) {
    return blockedCache;
  }

  const res = await fetch('/api/booking/blocked.php', {
    cache: 'no-store'
  });

  if (!res.ok) {
    throw new Error('B³¹d pobierania blocked.php');
  }

  const data = await res.json();

  blockedCache = data;
  blockedCacheTime = now;

  return data;
}

export function invalidateBlockedCache() {
  blockedCache = null;
  blockedCacheTime = 0;
}

const adminApi = {
  async saveBlockSettings(payload) {
    try {
      const res = await fetch('/api/booking/block-settings.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      });

      const data = await res.json();
      return data;

    } catch (err) {
      console.error('B³¹d zapisu ustawieñ blokad:', err);
    }
  }
};
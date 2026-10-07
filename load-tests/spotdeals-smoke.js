import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  vus: 5,
  duration: '30s',

  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<5000'],
  },
};

const pages = [
  'https://spotdeals.app/',
  'https://spotdeals.app/deals/miami',
  'https://spotdeals.app/deals/miami/happy-hour',
  'https://spotdeals.app/deals/miami/lunch-special',
  'https://spotdeals.app/deals/miami/daily-special',
];

export default function () {
  for (const url of pages) {
    const response = http.get(url, {
      headers: {
        'User-Agent': 'SpotDeals-k6-capacity-test/1.0',
      },
    });

    check(response, {
      'HTTP 200': (r) => r.status === 200,
    });

    sleep(1);
  }
}

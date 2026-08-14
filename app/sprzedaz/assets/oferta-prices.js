(() => {
  'use strict';

  const VAT_DIVISOR = 1.23;
  const PLAN_CODES = ['pro', 'vip'];

  function formatMoney(amount, currency, forceTwoDecimals = false) {
    const numericAmount = Number(amount);

    if (!Number.isFinite(numericAmount)) {
      return '';
    }

    const fractionDigits = forceTwoDecimals || !Number.isInteger(numericAmount) ? 2 : 0;
    const formattedAmount = numericAmount.toFixed(fractionDigits).replace('.', ',');
    const displayCurrency = currency === 'PLN' ? 'zł' : currency;

    return `${formattedAmount} ${displayCurrency}`.trim();
  }

  function normalizePrices(rows, expectedPlanCode) {
    if (!Array.isArray(rows)) {
      return null;
    }

    const pricesByPeriod = {};

    rows.forEach((row) => {
      const planCode = String(row?.plan_code || '').trim().toLowerCase();
      const billingPeriod = String(row?.billing_period || '').trim().toLowerCase();
      const amount = Number(row?.amount);
      const currency = String(row?.currency || '').trim().toUpperCase();

      if (
        planCode !== expectedPlanCode
        || !['monthly', 'yearly'].includes(billingPeriod)
        || !Number.isFinite(amount)
        || amount <= 0
        || !/^[A-Z]{3}$/.test(currency)
      ) {
        return;
      }

      pricesByPeriod[billingPeriod] = { amount, currency };
    });

    if (!pricesByPeriod.monthly || !pricesByPeriod.yearly) {
      return null;
    }

    return pricesByPeriod;
  }

  function findPriceElement(card, name) {
    return card.querySelector(`[data-offer-price="${name}"]`);
  }

  function showUnavailable(card) {
    const monthlyGross = findPriceElement(card, 'monthly-gross');
    const monthlyPeriod = findPriceElement(card, 'monthly-period');
    const details = findPriceElement(card, 'details');
    const yearlySavings = findPriceElement(card, 'yearly-savings');

    if (monthlyGross) {
      monthlyGross.textContent = 'Cena chwilowo niedostępna';
    }

    if (monthlyPeriod) {
      monthlyPeriod.hidden = true;
    }

    if (details) {
      details.hidden = true;
    }

    if (yearlySavings) {
      yearlySavings.hidden = true;
      yearlySavings.textContent = '';
    }
  }

  function renderPrices(card, prices) {
    const monthlyGross = findPriceElement(card, 'monthly-gross');
    const monthlyPeriod = findPriceElement(card, 'monthly-period');
    const monthlyNet = findPriceElement(card, 'monthly-net');
    const yearlyGross = findPriceElement(card, 'yearly-gross');
    const yearlyNet = findPriceElement(card, 'yearly-net');
    const details = findPriceElement(card, 'details');
    const yearlySavings = findPriceElement(card, 'yearly-savings');
    const monthly = prices.monthly;
    const yearly = prices.yearly;

    if (!monthlyGross || !monthlyPeriod || !monthlyNet || !yearlyGross || !yearlyNet || !details) {
      showUnavailable(card);
      return;
    }

    monthlyGross.textContent = `${formatMoney(monthly.amount, monthly.currency)} brutto`;
    monthlyPeriod.hidden = false;
    monthlyNet.textContent = `${formatMoney(monthly.amount / VAT_DIVISOR, monthly.currency, true)} netto / miesiąc`;
    yearlyGross.textContent = `${formatMoney(yearly.amount, yearly.currency)} brutto / rok`;
    yearlyNet.textContent = `${formatMoney(yearly.amount / VAT_DIVISOR, yearly.currency, true)} netto / rok`;
    details.hidden = false;

    if (yearlySavings) {
      const savings = monthly.amount * 12 - yearly.amount;

      if (monthly.currency === yearly.currency && savings > 0) {
        yearlySavings.textContent = `Przy płatności rocznej oszczędzasz ${formatMoney(savings, yearly.currency)} brutto.`;
        yearlySavings.hidden = false;
      } else {
        yearlySavings.textContent = '';
        yearlySavings.hidden = true;
      }
    }
  }

  async function loadPlanPrices(planCode) {
    const card = document.querySelector(`[data-offer-plan="${planCode}"]`);

    if (!card) {
      return;
    }

    showUnavailable(card);

    try {
      const response = await fetch(`/api/auth/register.php?action=plan_prices&plan=${encodeURIComponent(planCode)}`, {
        method: 'GET',
        headers: {
          'Accept': 'application/json'
        },
        cache: 'no-store'
      });
      const data = await response.json().catch(() => null);

      if (!response.ok || data?.success !== true) {
        throw new Error('price_unavailable');
      }

      const prices = normalizePrices(data.prices, planCode);

      if (!prices) {
        throw new Error('price_invalid');
      }

      renderPrices(card, prices);
    } catch {
      showUnavailable(card);
    }
  }

  PLAN_CODES.forEach((planCode) => {
    void loadPlanPrices(planCode);
  });
})();

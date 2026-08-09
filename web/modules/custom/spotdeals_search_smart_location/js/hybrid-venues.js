(function (Drupal, drupalSettings, once) {
  'use strict';

  function text(value) {
    return value === null || value === undefined ? '' : String(value);
  }

  function element(tag, className, content) {
    const node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (content !== undefined) {
      node.textContent = content;
    }
    return node;
  }

  function safeExternalUrl(value) {
    if (!value) {
      return '';
    }

    try {
      const url = new URL(value, window.location.origin);
      return ['http:', 'https:'].includes(url.protocol) ? url.toString() : '';
    }
    catch (error) {
      return '';
    }
  }

  const GENERIC_VENUE_WORDS = new Set([
    'and', 'bar', 'cafe', 'diner', 'food', 'grill', 'house', 'italian',
    'original', 'pizza', 'pizzeria', 'pub', 'restaurant', 'room', 'the'
  ]);

  function normalizeIdentityText(value) {
    return text(value)
      .normalize('NFKD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/&/g, ' and ')
      .replace(/[^a-z0-9]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function normalizeVenueTitle(value, city) {
    let title = normalizeIdentityText(value);
    const normalizedCity = normalizeIdentityText(city);

    if (normalizedCity) {
      const suffix = ' ' + normalizedCity;
      while (title.endsWith(suffix)) {
        title = title.slice(0, -suffix.length).trim();
      }
    }

    return title.replace(/^the\s+/, '').trim();
  }

  function distinctiveTitle(value, city) {
    return normalizeVenueTitle(value, city)
      .split(' ')
      .filter(function (word) {
        return word.length > 1 && !GENERIC_VENUE_WORDS.has(word);
      })
      .join(' ');
  }

  function normalizeStreetAddress(value) {
    let address = normalizeIdentityText(value);
    const replacements = [
      [/\bnorth\b/g, 'n'], [/\bsouth\b/g, 's'], [/\beast\b/g, 'e'], [/\bwest\b/g, 'w'],
      [/\bavenue\b/g, 'ave'], [/\bboulevard\b/g, 'blvd'], [/\bcourt\b/g, 'ct'],
      [/\bdrive\b/g, 'dr'], [/\bfreeway\b/g, 'fwy'], [/\bhighway\b/g, 'hwy'],
      [/\blane\b/g, 'ln'], [/\bparkway\b/g, 'pkwy'], [/\broad\b/g, 'rd'],
      [/\bstreet\b/g, 'st'], [/\bstate rd\b/g, ''], [/\bstate road\b/g, ''],
      [/\bsr\s*(\d+)\b/g, '$1'], [/\bfl\s*(\d+)\b/g, '$1']
    ];

    replacements.forEach(function (replacement) {
      address = address.replace(replacement[0], replacement[1]);
    });

    return address.replace(/\s+/g, ' ').trim();
  }

  function coordinateDistanceMiles(first, second) {
    const lat1 = Number(first && first.latitude);
    const lon1 = Number(first && first.longitude);
    const lat2 = Number(second && second.latitude);
    const lon2 = Number(second && second.longitude);

    if (![lat1, lon1, lat2, lon2].every(Number.isFinite)) {
      return null;
    }

    const radians = function (degrees) {
      return degrees * (Math.PI / 180);
    };
    const latitudeDelta = radians(lat2 - lat1);
    const longitudeDelta = radians(lon2 - lon1);
    const a = Math.sin(latitudeDelta / 2) ** 2
      + Math.cos(radians(lat1)) * Math.cos(radians(lat2))
      * Math.sin(longitudeDelta / 2) ** 2;

    return 3958.8 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function visibleDealVenueIdentities() {
    return Array.from(document.querySelectorAll('.spotdeals-deal-card[data-spotdeals-venue-title]'))
      .map(function (card) {
        return {
          title: card.dataset.spotdealsVenueTitle || '',
          address_line1: card.dataset.spotdealsVenueAddress || '',
          locality: card.dataset.spotdealsVenueCity || '',
          administrative_area: card.dataset.spotdealsVenueState || '',
          latitude: card.dataset.spotdealsVenueLat || '',
          longitude: card.dataset.spotdealsVenueLon || ''
        };
      })
      .filter(function (venue) {
        return venue.title !== '';
      });
  }

  function representsVisibleDealVenue(result, dealVenues) {
    const venue = result && result.venue ? result.venue : {};
    const address = venue.address || {};
    const city = address.locality || '';
    const state = normalizeIdentityText(address.administrative_area || '');
    const title = normalizeVenueTitle(venue.source_title || venue.title || '', city);
    const distinctive = distinctiveTitle(venue.source_title || venue.title || '', city);
    const street = normalizeStreetAddress(address.address_line1 || '');

    return dealVenues.some(function (dealVenue) {
      const dealCity = dealVenue.locality || '';
      if (normalizeIdentityText(dealCity) !== normalizeIdentityText(city)
        || normalizeIdentityText(dealVenue.administrative_area) !== state) {
        return false;
      }

      const dealTitle = normalizeVenueTitle(dealVenue.title, dealCity);
      const dealDistinctive = distinctiveTitle(dealVenue.title, dealCity);
      const dealStreet = normalizeStreetAddress(dealVenue.address_line1);
      const distance = coordinateDistanceMiles(venue, dealVenue);

      // Exact business-name matches in the same city suppress moved or
      // reformatted listings, such as Tiano's old and new street addresses.
      if (title !== '' && title === dealTitle) {
        return true;
      }

      // A matching distinctive name plus the same address or very close
      // coordinates catches branding variants such as Stavro's/Pizzeria.
      if (distinctive !== '' && distinctive === dealDistinctive
        && ((street !== '' && street === dealStreet)
          || (distance !== null && distance <= 0.25))) {
        return true;
      }

      // Exact street matches require at least one shared distinctive token,
      // avoiding suppression of unrelated businesses in a shared building.
      if (street !== '' && street === dealStreet) {
        const externalWords = new Set(distinctive.split(' ').filter(Boolean));
        return dealDistinctive.split(' ').some(function (word) {
          return externalWords.has(word);
        });
      }

      return false;
    });
  }

  function suppressVisibleDealVenues(results) {
    const dealVenues = visibleDealVenueIdentities();
    if (dealVenues.length === 0) {
      return results;
    }

    return results.filter(function (result) {
      return !representsVisibleDealVenue(result, dealVenues);
    });
  }

  function formatLocation(venue) {
    const address = venue.address || {};
    const parts = [
      address.address_line1,
      address.locality,
      address.administrative_area
    ].filter(Boolean);

    return parts.join(', ') || text(venue.formatted_address);
  }


  function googleMapsUrl(location) {
    if (!location) {
      return '';
    }

    const url = new URL('https://www.google.com/maps/search/');
    url.searchParams.set('api', '1');
    url.searchParams.set('query', location);
    return url.toString();
  }

  function distanceMiles(origin, venue) {
    const lat1 = Number(origin && origin.lat);
    const lon1 = Number(origin && origin.lon);
    const lat2 = Number(venue.latitude);
    const lon2 = Number(venue.longitude);

    if (![lat1, lon1, lat2, lon2].every(Number.isFinite)) {
      return null;
    }

    const radians = function (degrees) {
      return degrees * (Math.PI / 180);
    };
    const earthRadiusMiles = 3958.8;
    const latitudeDelta = radians(lat2 - lat1);
    const longitudeDelta = radians(lon2 - lon1);
    const a = Math.sin(latitudeDelta / 2) ** 2
      + Math.cos(radians(lat1)) * Math.cos(radians(lat2))
      * Math.sin(longitudeDelta / 2) ** 2;

    return earthRadiusMiles * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function formatDistance(origin, venue, strings) {
    const miles = distanceMiles(origin, venue);
    if (miles === null) {
      return '';
    }

    const displayDistance = Math.max(0.1, miles).toFixed(1);
    const template = strings.distance || Drupal.t('@distance miles away');
    return template.replace('@distance', displayDistance);
  }

  function createAction(label, url, modifier, external, strings) {
    const link = element('a', 'spotdeals-hybrid-venue-card__action ' + modifier);
    const labelNode = element('span', 'spotdeals-hybrid-venue-card__action-label', label);
    link.href = url;
    link.appendChild(labelNode);

    if (external) {
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.appendChild(element('span', 'spotdeals-hybrid-venue-card__external-icon', '↗'));
      link.setAttribute('aria-label', label + ' — ' + (strings.opensNewWindow || Drupal.t('opens in a new window')));
    }

    return link;
  }

  function createCard(result, settings) {
    const venue = result.venue || {};
    const spotdeals = result.spotdeals || {};
    const strings = settings.strings || {};
    const card = element('article', 'spotdeals-hybrid-venue-card');
    const body = element('div', 'spotdeals-hybrid-venue-card__body');
    const heading = element('h3', 'spotdeals-hybrid-venue-card__title', text(venue.title) || Drupal.t('Unnamed venue'));
    const location = formatLocation(venue);
    const typeName = venue.type && venue.type.name ? text(venue.type.name) : '';
    const distance = formatDistance(settings.origin, venue, strings);

    body.appendChild(heading);

    const metadata = element('div', 'spotdeals-hybrid-venue-card__metadata');
    if (typeName) {
      metadata.appendChild(element('span', 'spotdeals-hybrid-venue-card__type', typeName));
    }
    if (distance) {
      metadata.appendChild(element('span', 'spotdeals-hybrid-venue-card__distance', distance));
    }
    if (metadata.childNodes.length) {
      body.appendChild(metadata);
    }

    const mapsUrl = googleMapsUrl(location);
    if (location) {
      const address = element('p', 'spotdeals-hybrid-venue-card__address');
      const addressLink = element('a', 'spotdeals-hybrid-venue-card__address-link', location);
      addressLink.href = mapsUrl;
      addressLink.target = '_blank';
      addressLink.rel = 'noopener noreferrer';
      addressLink.setAttribute(
        'aria-label',
        location + ' — ' + (strings.openInMaps || Drupal.t('Open in Maps')) + ' — '
          + (strings.opensNewWindow || Drupal.t('opens in a new window'))
      );
      address.appendChild(addressLink);
      body.appendChild(address);
    }

    const badges = element('div', 'spotdeals-hybrid-venue-card__badges');
    if (result.persisted) {
      badges.appendChild(element('span', 'spotdeals-hybrid-venue-card__badge', Drupal.t('On SpotDeals')));
    }
    else {
      badges.appendChild(element(
        'span',
        'spotdeals-hybrid-venue-card__badge spotdeals-hybrid-venue-card__badge--external',
        strings.externalBadge || Drupal.t('External venue')
      ));
    }

    if (spotdeals.claimed === true) {
      badges.appendChild(element('span', 'spotdeals-hybrid-venue-card__badge', Drupal.t('Claimed')));
    }
    body.appendChild(badges);

    const actions = element('div', 'spotdeals-hybrid-venue-card__actions');
    if (mapsUrl) {
      actions.appendChild(createAction(
        strings.openInMaps || Drupal.t('Open in Maps'),
        mapsUrl,
        'spotdeals-hybrid-venue-card__action--maps',
        true,
        strings
      ));
    }

    if (result.persisted && venue.url) {
      actions.appendChild(createAction(
        Drupal.t('View venue'),
        venue.url,
        'spotdeals-hybrid-venue-card__action--primary',
        false,
        strings
      ));
    }
    else {
      const website = safeExternalUrl(venue.website);
      if (website) {
        actions.appendChild(createAction(
          Drupal.t('Visit website'),
          website,
          'spotdeals-hybrid-venue-card__action--primary',
          true,
          strings
        ));
      }

      if (settings.suggestUrl) {
        const suggestUrl = new URL(settings.suggestUrl, window.location.origin);
        suggestUrl.searchParams.set('venue_name', text(venue.title));
        suggestUrl.searchParams.set('venue_address', location);
        suggestUrl.searchParams.set('external_source', text(result.source));
        suggestUrl.searchParams.set('external_id', text(result.external_id));
        actions.appendChild(createAction(
          Drupal.t('Suggest a deal'),
          suggestUrl.toString(),
          website ? 'spotdeals-hybrid-venue-card__action--secondary' : 'spotdeals-hybrid-venue-card__action--primary',
          false,
          strings
        ));
      }

      if (settings.reportUrl) {
        const reportUrl = new URL(settings.reportUrl, window.location.origin);
        reportUrl.searchParams.set('venue_name', text(venue.title));
        reportUrl.searchParams.set('venue_address', location);
        reportUrl.searchParams.set('external_source', text(result.source));
        reportUrl.searchParams.set('external_id', text(result.external_id));
        actions.appendChild(createAction(
          strings.reportVenue || Drupal.t('Report closed or incorrect'),
          reportUrl.toString(),
          'spotdeals-hybrid-venue-card__action--report',
          false,
          strings
        ));
      }
    }

    if (actions.childNodes.length) {
      body.appendChild(actions);
    }
    card.appendChild(body);
    return card;
  }

  function ensurePromotionPrompt(description, strings) {
    const headerContent = description && description.parentElement;
    if (!headerContent || headerContent.querySelector('.spotdeals-hybrid-venues__prompt')) {
      return;
    }

    headerContent.appendChild(element(
      'p',
      'spotdeals-hybrid-venues__prompt',
      strings.promotionPrompt || Drupal.t('Know a promotion here? Help us add it.')
    ));
  }

  async function load(container, settings) {
    const origin = settings.origin;
    const strings = settings.strings || {};
    const title = container.querySelector('[data-spotdeals-hybrid-venues-title]');
    const description = container.querySelector('[data-spotdeals-hybrid-venues-description]');
    const status = container.querySelector('[data-spotdeals-hybrid-venues-status]');
    const results = container.querySelector('[data-spotdeals-hybrid-venues-results]');
    const count = container.querySelector('[data-spotdeals-hybrid-venues-count]');

    if (!origin || !Number.isFinite(Number(origin.lat)) || !Number.isFinite(Number(origin.lon))) {
      return;
    }

    title.textContent = strings.title || Drupal.t('Nearby venues');
    description.textContent = strings.description || Drupal.t('Places near your search area, including venues that may not have a SpotDeals promotion yet.');
    ensurePromotionPrompt(description, strings);

    container.hidden = false;
    container.classList.add('is-loading');
    container.classList.remove('has-error');
    status.textContent = Drupal.t('Finding nearby venues…');

    const url = new URL(settings.endpoint, window.location.origin);
    url.searchParams.set('query', text(settings.query));
    url.searchParams.set('lat', String(origin.lat));
    url.searchParams.set('lon', String(origin.lon));
    url.searchParams.set('radius', String(settings.radius || 5000));
    url.searchParams.set('limit', String(settings.limit || 6));

    try {
      const response = await fetch(url.toString(), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin'
      });

      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }

      const payload = await response.json();
      const data = payload && payload.ok === true ? payload.data : null;

      if (!data || data.contract_version !== settings.contractVersion || !Array.isArray(data.results)) {
        throw new Error('Unexpected venue response contract');
      }

      results.replaceChildren();

      const visibleResults = suppressVisibleDealVenues(data.results);

      if (visibleResults.length === 0) {
        count.textContent = '';
        status.textContent = Drupal.t('No additional nearby venues were found for this search area.');
        return;
      }

      visibleResults.forEach(function (result) {
        results.appendChild(createCard(result, settings));
      });

      count.textContent = Drupal.formatPlural(visibleResults.length, '1 venue', '@count venues');
      status.textContent = '';
    }
    catch (error) {
      container.classList.add('has-error');
      status.textContent = strings.loadError || Drupal.t('Nearby venues could not be loaded right now. Your deal results are still available above.');
    }
    finally {
      container.classList.remove('is-loading');
    }
  }

  Drupal.behaviors.spotdealsHybridVenues = {
    attach: function (context) {
      const settings = drupalSettings.spotdealsHybridVenues || {};

      once('spotdeals-hybrid-venues', '[data-spotdeals-hybrid-venues]', context).forEach(function (container) {
        if (!settings.endpoint) {
          return;
        }

        load(container, settings);
      });
    }
  };
})(Drupal, drupalSettings, once);

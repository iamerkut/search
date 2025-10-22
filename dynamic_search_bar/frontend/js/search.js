(function(){
  var input = document.querySelector('#dynamic-search-input');
  if(!input) return;

  var wrapper = document.createElement('div');
  wrapper.className = 'dsb-wrapper';

  var parent = input.parentNode;
  if (parent && parent !== document.body) {
    parent.insertBefore(wrapper, input);
    wrapper.appendChild(input);
  } else {
  wrapper.appendChild(input);
    document.body.appendChild(wrapper);
  }

  var box = document.createElement('div');
  box.id = 'dynamic-search-dropdown';
  box.setAttribute('role', 'listbox');
  box.setAttribute('aria-live', 'polite');
  box.style.display = 'none';
  wrapper.appendChild(box);

  var state = {
    term: '',
    timer: null,
    currentUrlIndex: 0,
    latestController: null,
    cursorIndex: -1,
    itemsFlat: [],
    cache: new Map(),
    popularLoaded: false
  };

  var ENDPOINTS = function(term){
    var encoded = encodeURIComponent(term);
    return [
      '/io.php?io=dsb_suggest&q=' + encoded,
      '/index.php?dsb_search=1&q=' + encoded
    ];
  };

  var TYPE_LABELS = {
    product: input.getAttribute('data-label-product') || 'Produkte',
    category: input.getAttribute('data-label-category') || 'Kategorien',
    manufacturer: input.getAttribute('data-label-manufacturer') || 'Hersteller'
  };

  function escapeHtml(str){
    return str.replace(/[&<>"']/g, function(ch){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[ch]);
    });
  }

  function highlight(label, term){
    var safeLabel = escapeHtml(label);
    if(!term){ return safeLabel; }
    var pattern = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return safeLabel.replace(new RegExp('('+pattern+')', 'ig'), '<mark>$1</mark>');
  }

  function flattenGroups(groups) {
    var flat = [];
    ['product', 'category', 'manufacturer'].forEach(function(type){
      if(groups[type]){
        flat = flat.concat(groups[type]);
      }
    });
    return flat;
  }

  function clearBox(){
    box.innerHTML = '';
    box.className = '';
    state.itemsFlat = [];
    state.cursorIndex = -1;
  }

  function hideBox(){
    clearBox();
    box.style.display = 'none';
  }

  function renderMessage(message, variant){
    clearBox();
    var msg = document.createElement('div');
    msg.className = 'dsb-message dsb-message--' + (variant || 'info');
    msg.textContent = message;
    box.appendChild(msg);
    box.style.display = 'block';
  }

  function renderResults(items, meta, term){
    clearBox();
    var groups = { product: [], category: [], manufacturer: [] };
    items.forEach(function(item){
      if(!groups[item.type]){ groups[item.type] = []; }
      groups[item.type].push(item);
    });

    var order = ['product', 'category', 'manufacturer'];
    order.forEach(function(type){
      if(!groups[type] || !groups[type].length){ return; }
      var section = document.createElement('div');
      section.className = 'dsb-section';

      var header = document.createElement('div');
      header.className = 'dsb-section__title';
      header.textContent = TYPE_LABELS[type] + ' (' + groups[type].length + ')';
      section.appendChild(header);

      var list = document.createElement('div');
      list.className = 'dsb-section__list';

      groups[type].forEach(function(item){
        var link = document.createElement('a');
        link.className = 'dsb-item';
        link.href = item.url;
        link.setAttribute('role', 'option');
        link.setAttribute('data-type', item.type);

        var body = document.createElement('span');
        body.className = 'dsb-item__body';
        body.innerHTML = '<span class="dsb-item__label">' + highlight(item.label, term) + '</span>';

        link.appendChild(body);
        list.appendChild(link);
      });

      section.appendChild(list);
      box.appendChild(section);
    });

    if (meta && meta.counts) {
      var footer = document.createElement('div');
      footer.className = 'dsb-footer';
      footer.innerHTML = '<div class="dsb-footer__stats">'
        + '<span>' + TYPE_LABELS.product + ': ' + (meta.counts.product || 0) + '</span>'
        + '<span>' + TYPE_LABELS.category + ': ' + (meta.counts.category || 0) + '</span>'
        + '<span>' + TYPE_LABELS.manufacturer + ': ' + (meta.counts.manufacturer || 0) + '</span>'
        + '</div>'
        + '<div class="dsb-footer__hint">⏎ bestätigt, ⬆⬇ navigiert, Esc schließt</div>';
      box.appendChild(footer);
    }

    box.style.display = 'block';
    state.itemsFlat = flattenGroups(groups);
    setActiveItem(0);
  }

  function renderPopular(items){
    clearBox();
    if(!items.length){
      return;
    }

    var section = document.createElement('div');
    section.className = 'dsb-section dsb-section--popular';

    var title = document.createElement('div');
    title.className = 'dsb-section__title';
    title.textContent = input.getAttribute('data-label-popular') || 'Beliebte Suchen';
    section.appendChild(title);

    var list = document.createElement('div');
    list.className = 'dsb-section__list dsb-section__list--popular';

    items.forEach(function(item){
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'dsb-popular-item';
      btn.innerHTML = '<span class="dsb-popular-item__text">' + escapeHtml(item.query) + '</span>'
        + '<span class="dsb-popular-item__meta">' + (item.hits || 0) + ' Suchanfragen</span>';
      btn.addEventListener('click', function(){
        input.value = item.query;
        state.term = item.query;
        fetchResults(item.query);
      });
      list.appendChild(btn);
    });

    section.appendChild(list);
    box.appendChild(section);
    box.style.display = 'block';
    state.popularLoaded = true;
  }

  function fetchPopular(){
    if(state.popularLoaded){
      return;
    }

    fetch('/index.php?dsb_api=1&action=popular', { cache: 'no-store' })
      .then(function(r){
        if(!r.ok){ throw new Error('HTTP ' + r.status); }
        return r.json();
      })
      .then(function(json){
        if(json && Array.isArray(json.popular) && json.popular.length){
          renderPopular(json.popular);
        }
      })
      .catch(function(err){
        console.warn('[DSB] popular fetch failed', err);
      });
  }

  function setActiveItem(index){
    var nodes = box.querySelectorAll('.dsb-item');
    if(!nodes.length){ return; }
    state.cursorIndex = index;
    nodes.forEach(function(node, idx){
      if(idx === index){
        node.classList.add('is-active');
        node.setAttribute('aria-selected', 'true');
        node.scrollIntoView({ block: 'nearest' });
      } else {
        node.classList.remove('is-active');
        node.setAttribute('aria-selected', 'false');
      }
    });
  }

  function moveCursor(delta){
    var nodes = box.querySelectorAll('.dsb-item');
    if(!nodes.length){ return; }
    var nextIndex = state.cursorIndex + delta;
    if(nextIndex < 0){ nextIndex = nodes.length - 1; }
    if(nextIndex >= nodes.length){ nextIndex = 0; }
    setActiveItem(nextIndex);
  }

  function fetchResults(term){
    if(state.latestController){ state.latestController.abort(); }

    state.currentUrlIndex = 0;
    var urls = ENDPOINTS(term);
    var controller = 'AbortController' in window ? new AbortController() : null;
    state.latestController = controller;

    renderMessage('Wird gesucht …', 'loading');

    var attempt = function(index){
      if(index >= urls.length){
        renderMessage('Keine Ergebnisse oder Dienst nicht erreichbar.', 'error');
        return;
      }

      var opts = { cache: 'no-store' };
      if(controller){ opts.signal = controller.signal; }

      fetch(urls[index], opts)
        .then(function(r){
          if(!r.ok){
            throw new Error('HTTP ' + r.status);
          }
          return r.text();
        })
        .then(function(txt){
          if(!txt || !txt.trim()){
            return { empty: true };
          }
          return JSON.parse(txt);
        })
        .then(function(json){
          if(json && json.error){
            throw new Error(json.message || 'BACKEND_ERROR');
          }
          if(json && json.empty){
            attempt(index + 1);
            return;
          }
          var items = Array.isArray(json.results) ? json.results : [];
          if(!items.length){
            renderMessage('Keine Ergebnisse gefunden.', 'empty');
            return;
          }
          state.cache.set(term, { results: items, meta: json.meta || null });
          renderResults(items, json.meta || null, term);
        })
        .catch(function(err){
          if(err.name === 'AbortError'){ return; }
          console.warn('[DSB] Endpoint failed:', urls[index], err);
          attempt(index + 1);
        });
    };

    attempt(0);
  }

  input.addEventListener('input', function(){
    clearTimeout(state.timer);
    var term = input.value.trim();
    var min = parseInt(input.getAttribute('data-min') || '3', 10);

    if(!term || term.length < min){
      hideBox();
      state.term = '';
      if(state.latestController){ state.latestController.abort(); }
      return;
    }

    state.term = term;
    state.timer = setTimeout(function(){ fetchResults(term); }, 350);
  });

  input.addEventListener('keydown', function(evt){
    if(evt.key === 'Escape'){
      hideBox();
      input.blur();
    } else if(evt.key === 'ArrowDown'){
      evt.preventDefault();
      moveCursor(1);
    } else if(evt.key === 'ArrowUp'){
      evt.preventDefault();
      moveCursor(-1);
    } else if(evt.key === 'Enter'){
      if(state.cursorIndex >= 0){
        var nodes = box.querySelectorAll('.dsb-item');
        if(nodes[state.cursorIndex]){
          nodes[state.cursorIndex].click();
        }
      }
    }
  });

  document.addEventListener('click', function(e){
    if(e.target !== input && !box.contains(e.target)){
      hideBox();
    }
  });

  input.addEventListener('focus', function(){
    var term = input.value.trim();
    if(!term){
      fetchPopular();
    }
  });
})();

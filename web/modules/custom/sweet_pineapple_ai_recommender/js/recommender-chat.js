(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.sweetPineappleRecommenderChat = {
    attach(context) {
      once('sweet-pineapple-recommender-chat', '[data-sp-recommender-chat]', context).forEach((chat) => {
        const settings = drupalSettings.sweetPineappleRecommender || {};
        const endpoint = settings.endpoint || '/tienda/recomendador/chat';
        const mascot = settings.mascot || '/themes/custom/sweet_pineapple/images/mascots/asesor-inteligente-pineapple.png';
        const conversationContext = Object.assign({}, settings.context || {});
        const form = chat.closest('.sp-recommender');
        const log = chat.querySelector('[data-sp-chat-log]');
        const input = chat.querySelector('[data-sp-chat-input]');
        const send = chat.querySelector('[data-sp-chat-send]');
        const storedHistory = Array.isArray(settings.history) ? settings.history.slice(-20) : [];
        const history = [];

        if (!form || !log || !input || !send) {
          return;
        }

        form.classList.add('sp-recommender--live-ready');

        // Envía el turno al endpoint Drupal y sincroniza filtros/resultados.
        const requestRecommendation = (message, appendUser = true) => {
          const requestHistory = history.slice(-8);
          if (appendUser) {
            appendUserMessage(log, message);
            history.push({ role: 'user', content: message });
            input.value = '';
            input.focus();
          }

          const pending = appendAssistantMessage(log, mascot, appendUser ? 'Estoy mirando el catálogo y cruzando sabores...' : 'Ya tengo tus filtros. Estoy buscando las mejores opciones...');
          scrollToBottom(log);

          fetch(endpoint, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
            },
            body: JSON.stringify({
              message,
              history: requestHistory,
              context: getContext(form, conversationContext),
            }),
          })
            .then((response) => {
              if (!response.ok) {
                throw new Error('Bad response');
              }
              return response.json();
            })
            .then((data) => {
              pending.remove();
              syncConversationContext(data.preferences || {}, form, conversationContext);
              const reply = data.reply || 'Tengo unas ideas para ti.';
              appendAssistantMessage(log, mascot, reply, data.recommendations || [], data.quick_replies || [], submitQuickReply);
              history.push({ role: 'assistant', content: reply });
              trimHistory(history);
              scrollToBottom(log);
            })
            .catch(() => {
              pending.remove();
              const reply = 'Ahora mismo no he podido consultar el catálogo. Prueba otra vez en unos segundos.';
              appendAssistantMessage(log, mascot, reply, [], [], submitQuickReply);
              history.push({ role: 'assistant', content: reply });
              trimHistory(history);
              scrollToBottom(log);
            });
        };

        renderStoredHistory(chat, mascot, storedHistory);

        const submitMessage = () => {
          const message = input.value.trim();
          if (!message) {
            input.focus();
            return;
          }

          requestRecommendation(message);
        };

        const submitQuickReply = (message) => {
          input.value = message;
          submitMessage();
        };

        send.addEventListener('click', (event) => {
          event.preventDefault();
          submitMessage();
        });
        input.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            submitMessage();
          }
        });

        if (hasUsefulContext(conversationContext)) {
          requestRecommendation('', false);
        }
      });
    },
  };

  // Pinta el mensaje escrito por el usuario.
  function appendUserMessage(log, message) {
    const row = document.createElement('div');
    row.className = 'sp-recommender__question sp-recommender__question--user';

    const bubble = document.createElement('div');
    bubble.className = 'sp-recommender__bubble sp-recommender__bubble--user';

    const text = document.createElement('p');
    text.textContent = message;

    bubble.append(text);
    row.append(bubble);
    log.append(row);
  }

  // Muestra el historial persistido sin reenviarlo automáticamente.
  function renderStoredHistory(chat, mascot, history) {
    if (!history.length) {
      return;
    }

    const details = document.createElement('details');
    details.className = 'sp-recommender__history';

    const summary = document.createElement('summary');
    summary.textContent = 'Ver historial de consultas';
    details.append(summary);

    const panel = document.createElement('div');
    panel.className = 'sp-recommender__history-panel';
    details.append(panel);

    history.slice(-10).forEach((item) => {
      if (item.role === 'user') {
        appendUserMessage(panel, item.content || '');
      }
      else if (item.role === 'assistant') {
        appendAssistantMessage(panel, mascot, item.content || '', [], [], () => {});
      }
    });

    chat.prepend(details);
  }

  function trimHistory(history) {
    if (history.length > 20) {
      history.splice(0, history.length - 20);
    }
  }

  function hasUsefulContext(context) {
    return ['store', 'occasion', 'people', 'family', 'flavor', 'style'].some((name) => Boolean(context[name]));
  }

  // Lee los filtros actuales del formulario para enviarlos como contexto.
  function getContext(form, conversationContext) {
    const context = Object.assign({}, conversationContext);
    ['store', 'occasion', 'people', 'family', 'flavor', 'style'].forEach((name) => {
      const field = form.querySelector(`[name="${name}"]:checked`) || form.querySelector(`[name="${name}"]`);

      if (field) {
        context[name] = field.value || '';
      }
    });

    return context;
  }

  // Refleja en la UI los filtros que el backend ha entendido.
  function syncConversationContext(preferences, form, conversationContext) {
    ['store', 'occasion', 'people', 'family', 'flavor', 'style'].forEach((name) => {
      if (!Object.prototype.hasOwnProperty.call(preferences, name)) {
        return;
      }

      const value = preferences[name] || '';
      conversationContext[name] = value;

      setFormValue(form, name, value);
    });
  }

  function setFormValue(form, name, value) {
    const fields = Array.from(form.querySelectorAll(`[name="${name}"]`));

    if (!fields.length) {
      return;
    }

    if (fields[0].type === 'radio') {
      if (value === '') {
        fields.forEach((field) => {
          field.checked = false;
          field.dispatchEvent(new Event('change', { bubbles: true }));
        });
        return;
      }

      const radio = fields.find((field) => field.value === value);
      if (radio) {
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
      }
      return;
    }

    const field = fields[0];
    if (field.tagName === 'SELECT') {
      const optionExists = Array.from(field.options).some((option) => option.value === value);
      if (!optionExists) {
        return;
      }
    }

    field.value = value;
    field.dispatchEvent(new Event('change', { bubbles: true }));
  }

  // Pinta respuesta, recomendaciones y botones rápidos.
  function appendAssistantMessage(log, mascot, message, recommendations, quickReplies, onQuickReply) {
    const row = document.createElement('div');
    row.className = 'sp-recommender__answer';

    const avatar = document.createElement('div');
    avatar.className = 'sp-recommender__avatar';

    const image = document.createElement('img');
    image.src = mascot;
    image.alt = '';
    avatar.append(image);

    const content = document.createElement('div');
    content.className = 'sp-recommender__bubble sp-recommender__bubble--assistant';

    const text = document.createElement('p');
    text.textContent = message;
    content.append(text);

    if (recommendations && recommendations.length) {
      content.append(buildRecommendationList(recommendations));
    }

    if (quickReplies && quickReplies.length) {
      content.append(buildQuickReplies(quickReplies, onQuickReply));
    }

    row.append(avatar, content);
    log.append(row);
    return row;
  }

  function buildQuickReplies(replies, onQuickReply) {
    const list = document.createElement('div');
    list.className = 'sp-recommender__quick-replies';

    replies.forEach((reply) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'sp-recommender__quick-reply';
      button.textContent = reply;
      button.addEventListener('click', () => onQuickReply(reply));
      list.append(button);
    });

    return list;
  }

  // Construye tarjetas compactas con productos reales del catálogo.
  function buildRecommendationList(recommendations) {
    const cards = document.createElement('div');
    cards.className = 'sp-recommender__live-cards';

    recommendations.forEach((item, index) => {
      const card = document.createElement('article');
      card.className = 'sp-recommender__live-card';

      if (item.image_url) {
        const media = document.createElement('a');
        media.className = 'sp-recommender__live-image';
        media.href = item.url;

        const image = document.createElement('img');
        image.src = item.image_url;
        image.alt = item.title || '';
        media.append(image);
        card.append(media);
      }

      const badge = document.createElement('p');
      badge.className = 'sp-recommender__badge';
      badge.textContent = index === 0 ? 'Mi favorita' : `Opción ${index + 1}`;

      const title = document.createElement('h4');
      title.textContent = item.title || 'Producto recomendado';

      const summary = document.createElement('p');
      summary.className = 'sp-recommender__summary';
      summary.textContent = item.summary || '';

      const link = document.createElement('a');
      link.className = 'sp-recommender__link';
      link.href = item.url;
      link.textContent = 'Ver producto';

      card.append(badge, title, summary, link);
      cards.append(card);
    });

    return cards;
  }

  function scrollToBottom(log) {
    log.scrollTop = log.scrollHeight;
  }
})(Drupal, drupalSettings, once);

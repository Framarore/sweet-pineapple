<?php

declare(strict_types=1);

namespace Drupal\sweet_pineapple_ai_recommender\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sweet_pineapple_ai_recommender\Service\ProductRecommender;
use Drupal\sweet_pineapple_ai_recommender\Service\RecommenderHistory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Página del recomendador: renderiza el chat y le pasa contexto inicial.
 */
final class ProductRecommenderForm extends FormBase {

  public function __construct(
    private readonly ProductRecommender $recommender,
    private readonly RecommenderHistory $historyStore,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('sweet_pineapple_ai_recommender.recommender'),
      $container->get('sweet_pineapple_ai_recommender.history'),
    );
  }

  public function getFormId(): string {
    return 'sweet_pineapple_ai_recommender_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $form['#attached']['library'][] = 'sweet_pineapple_ai_recommender/recommender';
    $form['#attributes']['class'][] = 'sp-recommender';
    $form['#method'] = 'get';
    $form['#cache']['max-age'] = 0;

    // Estos valores vienen de los filtros de la tienda y arrancan la conversación.
    $defaults = [
      'store' => (string) $request->query->get('store', ''),
      'occasion' => (string) $request->query->get('occasion', ''),
      'people' => (string) $request->query->get('people', ''),
      'family' => (string) $request->query->get('family', ''),
      'flavor' => (string) $request->query->get('flavor', ''),
      'style' => (string) $request->query->get('style', ''),
    ];
    $form['#attached']['drupalSettings']['sweetPineappleRecommender'] = [
      'endpoint' => \Drupal\Core\Url::fromRoute('sweet_pineapple_ai_recommender.chat')->toString(),
      'mascot' => '/themes/custom/sweet_pineapple/images/mascots/asesor-inteligente-pineapple.png',
      'context' => $defaults,
      'history' => $this->historyStore->getHistory(),
    ];

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['sp-recommender__conversation']],
      'mascot' => [
        '#markup' => '<div class="sp-recommender__mascot"><img src="/themes/custom/sweet_pineapple/images/mascots/asesor-inteligente-pineapple.png" alt="Asesor inteligente Sweet Pineapple"></div>',
      ],
      'messages' => [
        '#markup' => '<div class="sp-recommender__chat">'
          . '<div class="sp-recommender__bubble sp-recommender__bubble--assistant">'
          . '<p class="sp-recommender__eyebrow">Asesor inteligente</p>'
          . '<h2>Hola, soy tu asesor de Sweet Pineapple.</h2>'
          . '<p>Cuéntame qué tienes en mente y te propongo dulces reales de la tienda que encajen contigo.</p>'
          . '</div>'
          . '<div class="sp-recommender__bubble sp-recommender__bubble--user">'
          . '<p>Perfecto, te cuento lo que necesito.</p>'
          . '</div>'
          . '</div>',
      ],
    ];

    $context_summary = $this->buildContextSummary($defaults);
    if ($context_summary !== '') {
      $form['context_summary'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sp-recommender__context']],
        '#weight' => 1,
        'markup' => [
          '#markup' => '<p class="sp-recommender__context-title">' . $this->t('Filtros recibidos de la tienda') . '</p>' . $context_summary,
        ],
      ];
    }

    $form['live_chat'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['sp-recommender__live'],
        'data-sp-recommender-chat' => 'true',
      ],
      '#weight' => 5,
      'log' => [
        '#markup' => '<div class="sp-recommender__live-log" data-sp-chat-log>'
          . '<div class="sp-recommender__answer"><div class="sp-recommender__avatar"><img src="/themes/custom/sweet_pineapple/images/mascots/asesor-inteligente-pineapple.png" alt=""></div><div class="sp-recommender__bubble sp-recommender__bubble--assistant"><p>Estoy listo. Puedes hablarme natural: "somos 8, quiero chocolate para un cumple", "busco un regalo" o "no sé qué elegir".</p></div></div>'
          . '</div>',
      ],
      'composer' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['sp-recommender__composer']],
        'message' => [
          '#type' => 'textarea',
          '#title' => $this->t('Escribe al asesor inteligente'),
          '#title_display' => 'invisible',
          '#rows' => 2,
          '#attributes' => [
            'id' => 'sp-recommender-message',
            'data-sp-chat-input' => 'true',
            'placeholder' => $this->t('Escribe aquí lo que necesitas...'),
          ],
        ],
        'send' => [
          '#type' => 'button',
          '#value' => $this->t('Enviar'),
          '#limit_validation_errors' => [],
          '#executes_submit_callback' => FALSE,
          '#attributes' => [
            'class' => ['sp-recommender__send'],
            'data-sp-chat-send' => 'true',
          ],
        ],
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // El recomendador funciona por AJAX; el formulario no procesa envíos.
  }

  private function buildContextSummary(array $defaults): string {
    $chips = [];

    if ($defaults['store'] !== '') {
      $chips[] = $this->t('Tienda: @value', ['@value' => $this->recommender->getStoreLabel((int) $defaults['store'])]);
    }
    if ($defaults['people'] !== '') {
      $chips[] = $this->t('@count personas', ['@count' => $defaults['people']]);
    }
    foreach (['occasion', 'family', 'flavor', 'style'] as $key) {
      if ($defaults[$key] !== '') {
        $chips[] = $this->contextLabels()[$key][$defaults[$key]] ?? $defaults[$key];
      }
    }

    if ($chips === []) {
      return '';
    }

    $items = array_map(static fn($chip): string => '<span>' . htmlspecialchars((string) $chip, ENT_QUOTES, 'UTF-8') . '</span>', $chips);
    return '<div class="sp-recommender__context-chips">' . implode('', $items) . '</div>';
  }

  private function contextLabels(): array {
    return [
      'occasion' => ['cumpleanos' => $this->t('Celebración'), 'regalo' => $this->t('Regalo'), 'compartir' => $this->t('Compartir'), 'capricho' => $this->t('Capricho')],
      'family' => ['tartas' => $this->t('Tartas'), 'cookies' => $this->t('Cookies'), 'brownies' => $this->t('Brownies'), 'mini' => $this->t('Mini dulces'), 'bolleria' => $this->t('Bollería')],
      'flavor' => ['chocolate' => $this->t('Chocolate'), 'pistacho' => $this->t('Pistacho'), 'caramelo' => $this->t('Caramelo'), 'galleta' => $this->t('Galleta'), 'fruta' => $this->t('Fruta'), 'citricos' => $this->t('Cítricos'), 'cafe' => $this->t('Café'), 'frutos secos' => $this->t('Frutos secos'), 'frutos-secos' => $this->t('Frutos secos'), 'premium' => $this->t('Premium')],
      'style' => ['intenso' => $this->t('Intenso'), 'original' => $this->t('Original'), 'premium' => $this->t('Premium'), 'clasico' => $this->t('Clásico')],
    ];
  }

}

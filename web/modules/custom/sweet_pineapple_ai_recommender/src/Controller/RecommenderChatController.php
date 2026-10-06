<?php

declare(strict_types=1);

namespace Drupal\sweet_pineapple_ai_recommender\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\sweet_pineapple_ai_recommender\Service\ProductRecommender;
use Drupal\sweet_pineapple_ai_recommender\Service\RecommenderHistory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class RecommenderChatController extends ControllerBase {

  private const FIELDS = ['store', 'occasion', 'people', 'excluded_people', 'family', 'flavor', 'excluded_flavors', 'style'];
  private const NEGATIVE_TRIGGERS = ['no ', 'ni ', 'sin ', 'mejor sin', 'prefiero sin', 'nada de', 'evita', 'evitar', 'mal', 'incorrect', 'equivoc', 'quita', 'quitar', 'cambia', 'cambiar'];
  private const FLAVOR_NEGATIONS = ['mejor sin', 'prefiero sin', 'preferimos sin', 'sin ', 'nada de', 'no quiero', 'ni quiero', 'no queremos', 'no me gusta', 'no nos gusta', 'evita', 'evitar', 'no sea', 'ni sea', 'que no sea', 'que no sea de', 'no sea de', 'ni sea de', 'no lleve', 'ni lleve', 'que no lleve', 'no tenga', 'ni tenga', 'que no tenga'];
  private const PEOPLE_NEGATIONS = ['no somos', 'ni somos', 'no sois', 'ni sois', 'no es para', 'ni para', 'no para', 'ni '];
  private const MAPS = [
    'occasion' => [
      'cumpleanos' => ['cumple', 'cumpleanos', 'celebracion', 'fiesta', 'aniversario', 'boda', 'comunion'],
      'regalo' => ['regalo', 'detalle', 'sorpresa', 'llevar', 'invitar'],
      'compartir' => ['compartir', 'familia', 'amigos', 'oficina', 'reunion', 'grupo', 'mesa'],
      'capricho' => ['capricho', 'antojo', 'merienda', 'yo solo', 'para mi', 'personal'],
    ],
    'family' => [
      'tartas' => ['cheesecake', 'tarta', 'layer cake'],
      'cookies' => ['megacookie', 'cookie', 'galleta'],
      'brownies' => ['brownie', 'blondie'],
      'mini' => ['cupcake', 'mini dulce', 'mini dulces'],
      'bolleria' => ['roll', 'brioche', 'bolleria', 'bollo'],
    ],
    'flavor' => [
      'chocolate' => ['chocolate', 'choco', 'cacao', 'hippo', 'brownie'],
      'pistacho' => ['pistacho', 'pistac'],
      'caramelo' => ['caramelo', 'lotus', 'toffee'],
      'galleta' => ['galleta', 'cookie', 'oreo'],
      'fruta' => ['fresa', 'frambuesa', 'frutos rojos', 'mango'],
      'citricos' => ['limon', 'naranja', 'citrico'],
      'coco' => ['coco'],
      'cafe' => ['cafe', 'tiramisu'],
      'frutos secos' => ['avellana', 'almendra', 'nuez', 'frutos secos'],
      'especias' => ['canela', 'especias'],
      'clasico' => ['clasico', 'tradicional', 'normal'],
      'premium' => ['especial', 'premium', 'dubai'],
    ],
    'style' => [
      'intenso' => ['intenso', 'potente', 'mucho sabor', 'fuerte'],
      'original' => ['original', 'diferente', 'creativo', 'sorprender'],
      'premium' => ['premium', 'especial', 'elegante', 'lujo'],
      'clasico' => ['clasico', 'tradicional'],
    ],
  ];
  private const LABELS = [
    'occasion' => ['cumpleanos' => 'cumpleaños o celebración', 'regalo' => 'regalo', 'compartir' => 'compartir', 'capricho' => 'capricho personal'],
    'family' => ['tartas' => 'tartas y cheesecakes', 'cookies' => 'cookies y megacookies', 'brownies' => 'brownies y blondies', 'mini' => 'mini dulces', 'bolleria' => 'bollería dulce'],
    'flavor' => ['chocolate' => 'chocolate', 'pistacho' => 'pistacho', 'caramelo' => 'caramelo o Lotus', 'galleta' => 'galleta', 'fruta' => 'fruta', 'citricos' => 'cítricos', 'coco' => 'coco', 'cafe' => 'café', 'frutos secos' => 'frutos secos', 'especias' => 'especias', 'clasico' => 'clásico', 'premium' => 'algo especial', 'vainilla' => 'vainilla'],
    'style' => ['intenso' => 'intenso', 'original' => 'original', 'premium' => 'premium', 'clasico' => 'clásico'],
  ];
  private const HUMAN_LABELS = [
    'occasion' => ['cumpleanos' => 'para una celebración', 'regalo' => 'como regalo', 'compartir' => 'para compartir', 'capricho' => 'como capricho'],
    'family' => ['tartas' => 'en familia tartas', 'cookies' => 'en familia cookies', 'brownies' => 'en familia brownies', 'mini' => 'en mini dulces', 'bolleria' => 'en bolleria'],
    'flavor' => ['*' => 'con toque de '],
    'style' => ['*' => 'en estilo '],
  ];

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

  /**
   * Responde al chat y devuelve filtros, texto y productos en JSON.
   */
  public function reply(Request $request): JsonResponse {
    if (!$request->isMethod('POST')) {
      return new JsonResponse(['message' => 'Endpoint del chat del asesor inteligente. La página de uso está en /tienda/recomendador.']);
    }

    $payload = json_decode($request->getContent(), TRUE) ?: [];
    $message = trim((string) ($payload['message'] ?? ''));
    $history = is_array($payload['history'] ?? NULL) ? $payload['history'] : [];
    $context = is_array($payload['context'] ?? NULL) ? $payload['context'] : [];

    // Orden de prioridad: historial visible, filtros de tienda y mensaje nuevo.
    $message_preferences = $this->inferPreferences($message);
    $preferences = $this->mergePreferences(
      $this->mergePreferences($this->inferHistoryPreferences($history), $this->contextPreferences($context)),
      $message_preferences,
    );
    $preferences = $this->applyPreferenceCorrections($message, $preferences, $message_preferences);

    $intent = $message === '' && array_filter($this->contextPreferences($context))
      ? 'recommendation'
      : $this->detectIntent($message, $history, $preferences);
    $results = in_array($intent, ['recommendation', 'store_info'], TRUE) && ($intent !== 'store_info' || !empty($preferences['store']))
      ? $this->recommender->getRecommendations($preferences)
      : [];
    $response = $this->buildLocalConversation($intent, $preferences, $results);

    if ($message !== '') {
      $this->historyStore->append($message, $response['reply']);
    }

    return new JsonResponse([
      'reply' => $response['reply'],
      'mode' => 'local_conversation',
      'preferences' => $preferences,
      'recommendations' => $results,
      'quick_replies' => $response['quick_replies'],
      'history' => $this->historyStore->getHistory(),
    ]);
  }

  /**
   * Extrae filtros de los mensajes que el usuario mantiene en esta sesión.
   */
  private function inferHistoryPreferences(array $history): array {
    $preferences = $this->defaultPreferences();
    foreach ($history as $item) {
      if (($item['role'] ?? '') === 'user') {
        $message = (string) ($item['content'] ?? '');
        $message_preferences = $this->inferPreferences($message);
        $preferences = $this->mergePreferences($preferences, $message_preferences);
        $preferences = $this->applyPreferenceCorrections($message, $preferences, $message_preferences);
      }
    }
    return $preferences;
  }

  /**
   * Convierte lenguaje natural sencillo en filtros del catálogo.
   */
  private function inferPreferences(string $message): array {
    $text = $this->normalize($message);
    $preferences = $this->defaultPreferences();
    $people = $this->extractPeoplePreference($text);

    if ($people !== '') {
      $preferences['people'] = $people;
    }
    if (($store_id = $this->recommender->findStoreIdByText($message)) !== NULL) {
      $preferences['store'] = (string) $store_id;
    }

    foreach (['occasion', 'family', 'style'] as $field) {
      if (($value = $this->firstMatchingValue($text, self::MAPS[$field])) !== '') {
        $preferences[$field] = $value;
      }
    }
    if (($flavor = $this->firstMatchingValue($text, self::MAPS['flavor'], TRUE)) !== '') {
      $preferences['flavor'] = $flavor;
    }

    return $preferences;
  }

  /**
   * Limpia valores previos cuando el usuario corrige o niega algo.
   */
  private function applyPreferenceCorrections(string $message, array $preferences, array $message_preferences): array {
    $text = $this->normalize($message);
    if ($text === '' || !$this->containsAny($text, self::NEGATIVE_TRIGGERS)) {
      return $preferences;
    }

    $preferences = $this->mergeExclusions($preferences, 'excluded_people', $this->negatedPeopleValues($text));
    if ($this->negatesPeople($text) && empty($message_preferences['people'])) {
      $preferences['people'] = '';
    }

    $excluded_flavors = $this->negatedFlavors($text);
    $preferences = $this->mergeExclusions($preferences, 'excluded_flavors', $excluded_flavors);
    if (!empty($message_preferences['flavor']) && !in_array($message_preferences['flavor'], $excluded_flavors, TRUE)) {
      $preferences['excluded_flavors'] = array_values(array_diff($preferences['excluded_flavors'] ?? [], [$message_preferences['flavor']]));
    }
    if ($excluded_flavors !== [] && (empty($message_preferences['flavor']) || in_array($message_preferences['flavor'], $excluded_flavors, TRUE))) {
      $preferences['flavor'] = '';
    }
    if (!empty($preferences['flavor']) && in_array($preferences['flavor'], $preferences['excluded_flavors'] ?? [], TRUE)) {
      $preferences['flavor'] = '';
    }

    return $preferences;
  }

  /**
   * Normaliza y valida los filtros que llegan desde la página de tienda.
   */
  private function contextPreferences(array $context): array {
    $store = (int) ($context['store'] ?? 0);
    $flavor = str_replace('-', ' ', (string) ($context['flavor'] ?? ''));

    return [
      'store' => $store > 0 ? (string) $store : '',
      'occasion' => $this->validContextValue($context, 'occasion'),
      'people' => (string) ($context['people'] ?? '') !== '' ? (string) max(1, min(30, (int) $context['people'])) : '',
      'excluded_people' => [],
      'family' => $this->validContextValue($context, 'family'),
      'flavor' => in_array($flavor, array_merge(array_keys(self::MAPS['flavor']), ['vainilla']), TRUE) ? $flavor : '',
      'excluded_flavors' => [],
      'style' => $this->validContextValue($context, 'style'),
    ];
  }

  /**
   * Decide si toca recomendar, informar de tiendas o contestar conversacion ligera.
   */
  private function detectIntent(string $message, array $history, array $preferences): string {
    $text = $this->normalize($message);
    if ($this->containsAny($text, ['quien eres', 'que eres', 'como funcionas', 'eres una ia', 'eres inteligencia'])) {
      return 'identity';
    }
    if ($this->recommender->findStoreIdByText($message) !== NULL || $this->containsAny($text, ['en que tienda', 'que tienda', 'esta tienda', 'que tienes', 'que hay', 'donde', 'recoger', 'recogida', 'disponible', 'disponibilidad', 'lo tienes', 'teneis'])) {
      return 'store_info';
    }
    if (($this->negatesPeople($text) || $this->negatesFlavor($text)) && array_filter($preferences)) {
      return 'recommendation';
    }
    if ($this->containsAny($text, ['gracias', 'perfecto', 'genial', 'vale', 'ok'])) {
      return array_filter($preferences) ? 'recommendation' : 'thanks';
    }
    if ($this->containsAny($text, ['hola', 'buenas', 'hey', 'holi', 'hello'])) {
      return array_filter($preferences) ? 'recommendation' : 'greeting';
    }
    if ($this->shouldRecommendProducts($text)) {
      return 'recommendation';
    }

    foreach (array_slice($history, -4) as $item) {
      $assistant_text = ($item['role'] ?? '') === 'assistant' ? $this->normalize((string) ($item['content'] ?? '')) : '';
      if ($this->containsAny($assistant_text, ['sabor', 'personas', 'ocasion', 'estilo', 'recomendar'])) {
        return 'recommendation';
      }
    }
    return 'smalltalk';
  }

  private function buildLocalConversation(string $intent, array $preferences, array $results): array {
    return match ($intent) {
      'greeting' => $this->answer('Hola, soy tu asesor inteligente de Sweet Pineapple. Cuéntame para quién es, cuántas personas sois y qué sabor te apetece, y te llevo directo a una buena opción.', ['Quiero algo para un cumpleaños', 'Somos 8 y nos gusta el chocolate', 'Busco un regalo dulce']),
      'thanks' => $this->answer('De nada. Me quedo por aquí por si quieres afinar sabor, tamaño o estilo.', ['Quiero algo más especial', 'Mejor para compartir', 'Enséñame chocolate']),
      'identity' => $this->answer('Soy el asesor inteligente de la tienda: leo lo que me cuentas, detecto ocasión, personas y sabores, y lo cruzo con el catálogo real de Sweet Pineapple para recomendarte algo con sentido.', ['Recomiéndame algo', 'Somos 6 personas', 'Quiero pistacho']),
      'store_info' => $this->buildStoreInfoReply($preferences, $results),
      'recommendation' => $this->buildRecommendationReply($preferences, $results),
      default => $this->answer('Te sigo. Puedo charlar un poquito, pero donde más fino voy es ayudándote a elegir dulces. Dime algo como "somos 8 y quiero chocolate para un cumple" y te hago de asesor.', ['Somos 8 y quiero chocolate', 'Es para regalar', 'Quiero algo original']),
    };
  }

  /**
   * Redacta una recomendación con lo entendido y lo que falta.
   */
  private function buildRecommendationReply(array $preferences, array $results): array {
    $missing = $this->missingPreferences($preferences);
    if ($results === []) {
      return $this->answer('Necesito una pista más para recomendar bien. Lo ideal es saber ocasión, número de personas y algún sabor que te apetezca.', $this->quickRepliesForMissing($missing, $preferences));
    }

    $reply = $this->interpretedSearchSummary($preferences) . ' ';
    $reply .= 'Con eso, mi primera apuesta es ' . $results[0]['title'] . '. ';
    $reply .= $this->humanPreferenceSummary($preferences);
    $reply .= !empty($preferences['store']) ? ' Lo estoy mirando con el contexto de ' . $this->recommender->getStoreLabel((int) $preferences['store']) . '.' : '';
    $reply .= $missing !== []
      ? ' Puedo afinar más si me dices ' . $this->humanMissingSummary($missing) . '.'
      : ' Te dejo también un par de alternativas por si quieres comparar.';

    return $this->answer($reply, $this->quickRepliesForMissing($missing, $preferences));
  }

  private function buildStoreInfoReply(array $preferences, array $results): array {
    if (empty($preferences['store'])) {
      return $this->answer('Todavía no me has dicho tienda. Elige una en el selector de tienda o dime Centro, Norte o Sur, y te lo miro con esa sucursal.', ['Sweet Pineapple Centro', 'Sweet Pineapple Norte', 'Sweet Pineapple Sur']);
    }

    $store_label = $this->recommender->getStoreLabel((int) $preferences['store']);
    if ($results === []) {
      return $this->answer('Lo estoy mirando para ' . $store_label . ', pero necesito saber qué producto o sabor buscas para orientarte bien.', ['Chocolate', 'Pistacho', 'Es para un cumpleaños']);
    }

    $intro = !empty($preferences['flavor'])
      ? 'En ' . $store_label . ', de ' . $preferences['flavor'] . ' te enseñaría primero '
      : 'En ' . $store_label . ', ahora te enseñaría primero ';
    $reply = $this->interpretedSearchSummary($preferences) . ' ' . $intro . $results[0]['title'] . '. ';
    $reply .= 'No llevo stock por unidades en tiempo real, pero si uso las ventas de esa tienda para ordenar mejor las opciones. ';
    $reply .= $this->storeReasonSummary($results[0], $store_label);

    return $this->answer($reply, ['Es para un cumpleaños', 'Somos 8 personas', 'Quiero otra tienda']);
  }

  private function storeReasonSummary(array $product, string $store_label): string {
    foreach ($product['reasons'] ?? [] as $reason) {
      if (str_contains($this->normalize((string) $reason), 'popular en')) {
        return $reason . '.';
      }
    }
    return 'Para confirmar recogida o disponibilidad exacta en ' . $store_label . ', el siguiente paso seria seleccionar el producto y finalizar el pedido.';
  }

  private function interpretedSearchSummary(array $preferences): string {
    $parts = $this->preferenceParts($preferences, [
      'store' => fn(string $value): string => 'tienda: ' . $this->recommender->getStoreLabel((int) $value),
      'occasion' => fn(string $value): string => 'ocasión: ' . $this->labelForPreference('occasion', $value),
      'people' => static fn(string $value): string => 'personas: ' . $value,
      'family' => fn(string $value): string => 'familia: ' . $this->labelForPreference('family', $value),
      'flavor' => fn(string $value): string => 'sabor: ' . $this->labelForPreference('flavor', $value),
      'style' => fn(string $value): string => 'estilo: ' . $this->labelForPreference('style', $value),
    ]);
    $parts = array_merge($parts, $this->excludedParts($preferences, 'excluded_people', static fn(string $value): string => $value . ' personas'));
    $parts = array_merge($parts, $this->excludedParts($preferences, 'excluded_flavors', fn(string $value): string => $this->labelForPreference('flavor', $value)));

    return $parts === []
      ? 'Todavía no he detectado filtros concretos.'
      : 'He entendido tu mensaje y he marcado estos filtros: ' . implode(', ', $parts) . '.';
  }

  private function humanPreferenceSummary(array $preferences): string {
    $parts = [];
    foreach (['occasion', 'family', 'flavor', 'style'] as $field) {
      if (!empty($preferences[$field])) {
        $parts[] = self::HUMAN_LABELS[$field][$preferences[$field]] ?? self::HUMAN_LABELS[$field]['*'] . $preferences[$field];
      }
    }
    if (!empty($preferences['people'])) {
      $parts[] = 'para ' . $preferences['people'] . ' personas';
    }
    foreach (($preferences['excluded_people'] ?? []) as $people) {
      $parts[] = 'no para ' . $people . ' personas';
    }
    foreach (($preferences['excluded_flavors'] ?? []) as $flavor) {
      $parts[] = 'sin ' . $this->labelForPreference('flavor', $flavor);
    }

    return $parts === [] ? 'Te lo propongo como opción versátil de la tienda.' : 'Encaja ' . implode(', ', $parts) . '.';
  }

  private function quickRepliesForMissing(array $missing, array $preferences = []): array {
    if (in_array('occasion', $missing, TRUE)) {
      return ['Es para un cumpleaños', 'Es para regalar', 'Es para compartir'];
    }
    if (in_array('people', $missing, TRUE)) {
      return $this->filterQuickReplies(['4' => 'Somos 4 personas', '8' => 'Somos 8 personas', '12' => 'Somos 12 personas'], $preferences['excluded_people'] ?? [], ['Somos 4 personas', 'Somos 12 personas', 'Somos 16 personas']);
    }
    if (in_array('flavor', $missing, TRUE)) {
      return $this->filterQuickReplies(['chocolate' => 'Chocolate', 'pistacho' => 'Pistacho', 'caramelo' => 'Caramelo o Lotus'], $preferences['excluded_flavors'] ?? [], ['Pistacho', 'Caramelo o Lotus', 'Fruta']);
    }
    return ['Quiero algo más original', 'Prefiero algo premium', 'Enséñame otra opción'];
  }

  private function defaultPreferences(): array {
    $preferences = array_fill_keys(self::FIELDS, '');
    $preferences['excluded_people'] = [];
    $preferences['excluded_flavors'] = [];
    return $preferences;
  }

  private function mergePreferences(array $current, array $incoming): array {
    foreach ($incoming as $key => $value) {
      if ($value !== '' && $value !== []) {
        $current[$key] = $value;
        if ($key === 'flavor') {
          $current['excluded_flavors'] = array_values(array_diff($current['excluded_flavors'] ?? [], [$value]));
        }
      }
    }
    return $current;
  }

  private function mergeExclusions(array $preferences, string $key, array $values): array {
    if ($values !== []) {
      $preferences[$key] = array_values(array_unique(array_merge($preferences[$key] ?? [], $values)));
    }
    return $preferences;
  }

  private function extractPeoplePreference(string $text): string {
    preg_match_all('/\b(\d{1,2})\b/', $text, $matches, PREG_OFFSET_CAPTURE);
    $people = '';
    foreach ($matches[1] as [$number, $offset]) {
      if (!$this->containsAny(substr($text, max(0, $offset - 24), 24), self::PEOPLE_NEGATIONS)) {
        $people = (string) max(1, min(30, (int) $number));
      }
    }
    return $people;
  }

  private function negatedPeopleValues(string $text): array {
    preg_match_all('/\b\d{1,2}\b/', $text, $matches, PREG_OFFSET_CAPTURE);
    $people = [];
    foreach ($matches[0] as [$number, $offset]) {
      if ($this->containsAny(substr($text, max(0, $offset - 28), 28), self::PEOPLE_NEGATIONS)) {
        $people[] = (string) max(1, min(30, (int) $number));
      }
    }
    return array_values(array_unique($people));
  }

  private function negatesPeople(string $text): bool {
    return (bool) preg_match('/\b(no|ni)\s+(somos|sois|seremos|personas|para|\d{1,2})\b/', $text)
      || $this->containsAny($text, ['no somos', 'ni somos', 'no sois', 'ni sois', 'no personas']);
  }

  private function negatedFlavors(string $text): array {
    $flavors = [];
    foreach (self::MAPS['flavor'] as $flavor => $needles) {
      foreach ($needles as $needle) {
        $offset = strpos($text, $needle);
        if ($offset !== FALSE && $this->containsAny($this->flavorContextBefore($text, $offset), self::FLAVOR_NEGATIONS)) {
          $flavors[] = $flavor;
          break;
        }
      }
    }
    return array_values(array_unique($flavors));
  }

  private function negatesFlavor(string $text): bool {
    return $this->negatedFlavors($text) !== [];
  }

  private function firstMatchingValue(string $text, array $map, bool $respect_negations = FALSE): string {
    foreach ($map as $value => $needles) {
      if ($respect_negations ? $this->containsAnyAffirmed($text, $needles) : $this->containsAny($text, $needles)) {
        return (string) $value;
      }
    }
    return '';
  }

  private function containsAnyAffirmed(string $text, array $needles): bool {
    foreach ($needles as $needle) {
      $offset = strpos($text, $needle);
      if ($offset !== FALSE && !$this->containsAny($this->flavorContextBefore($text, $offset), self::FLAVOR_NEGATIONS)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function flavorContextBefore(string $text, int $offset): string {
    $context = substr($text, max(0, $offset - 40), min(40, $offset));
    $boundaries = [',', '.', ';', ':', ' pero ', ' prefiero ', ' mejor ', ' ahora ', ' en vez de ', ' antes '];

    foreach ($boundaries as $boundary) {
      $position = strrpos($context, $boundary);
      if ($position !== FALSE) {
        $context = substr($context, $position + strlen($boundary));
      }
    }

    return $context;
  }

  private function containsAny(string $text, array $needles): bool {
    foreach ($needles as $needle) {
      if (str_contains($text, $needle)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function shouldRecommendProducts(string $text): bool {
    return $this->containsAny($text, ['dulce', 'tarta', 'pastel', 'postre', 'producto', 'catalogo', 'recomienda', 'recomendacion', 'recomiendame', 'recomendar', 'cookie', 'brownie', 'blondie', 'cupcake', 'roll', 'brioche', 'fruta', 'limon', 'fresa', 'cafe', 'cheesecake', 'regalo', 'cumple', 'quiero', 'busco', 'necesito', 'apetece']);
  }

  private function validContextValue(array $context, string $key): string {
    $value = (string) ($context[$key] ?? '');
    return in_array($value, array_keys(self::MAPS[$key]), TRUE) ? $value : '';
  }

  private function labelForPreference(string $type, string $value): string {
    return self::LABELS[$type][$value] ?? $value;
  }

  private function missingPreferences(array $preferences): array {
    return array_values(array_filter(['occasion', 'people', 'flavor'], static fn(string $key): bool => empty($preferences[$key])));
  }

  private function humanMissingSummary(array $missing): string {
    $labels = ['occasion' => 'la ocasión', 'people' => 'cuántas personas sois', 'flavor' => 'el sabor que más te apetece'];
    return implode(' y ', array_map(static fn(string $key): string => $labels[$key], $missing));
  }

  private function preferenceParts(array $preferences, array $formatters): array {
    $parts = [];
    foreach ($formatters as $key => $formatter) {
      if (!empty($preferences[$key])) {
        $parts[] = $formatter((string) $preferences[$key]);
      }
    }
    return $parts;
  }

  private function excludedParts(array $preferences, string $key, callable $label): array {
    return empty($preferences[$key]) ? [] : ['evitando: ' . implode(', ', array_map($label, $preferences[$key]))];
  }

  private function filterQuickReplies(array $options, array $excluded, array $fallback): array {
    $replies = [];
    foreach ($options as $value => $label) {
      if (!in_array((string) $value, $excluded, TRUE)) {
        $replies[] = $label;
      }
    }
    return $replies ?: $fallback;
  }

  private function answer(string $reply, array $quick_replies): array {
    return ['reply' => $reply, 'quick_replies' => $quick_replies];
  }

  private function normalize(string $text): string {
    $text = mb_strtolower($text);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    return is_string($ascii) ? $ascii : $text;
  }

}

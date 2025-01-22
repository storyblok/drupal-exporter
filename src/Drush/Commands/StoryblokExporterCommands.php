<?php

namespace Drupal\storyblok_exporter\Drush\Commands;

use Storyblok\Mapi\Endpoints\AssetApi;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drupal\node\NodeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Storyblok\Mapi\MapiClient;

/**
 * A Drush commandfile.
 */
final class StoryblokExporterCommands extends DrushCommands {

  use AutowireTrait;

  private const COMPONENT_TYPE = 'article';

  private MapiClient $storyblokClient;
  private string $spaceId;

  /**
   * Constructs a StoryblokExporterCommands object.
   */
  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private DateFormatterInterface $dateFormatter,
    private FileUrlGeneratorInterface $fileUrlGenerator,
    private FileSystemInterface $fileSystem,
  ) {
    parent::__construct();
    $this->storyblokClient = MapiClient::init(Settings::get('STORYBLOK_OAUTH_TOKEN'));
    $this->spaceId = Settings::get('STORYBLOK_SPACE_ID');
  }

  /**
   * Exports Drupal articles to Storyblok.
   */
  #[CLI\Command(name: 'storyblok_exporter:export', aliases: ['sbe'])]
  #[CLI\Option(name: 'limit', description: 'LIMIT the number of nodes to export')]
  #[CLI\Usage(name: 'storyblok_exporter:export --limit=10', description: 'Export and migrate up to 10 articles to Storyblok')]
  public function commandName($options = ['limit' => NULL]) {
    $query = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('type', self::COMPONENT_TYPE);

    if ($options['limit']) {
      $query->range(0, $options['limit']);
    }

    $nids = $query->execute();
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    $toBeExported = [];
    foreach ($nodes as $node) {
      $toBeExported[] = [
        'title' => $node->label(),
        'body' => $node->get('body')->value,
        'created_date' => $this->dateFormatter->format($node->getCreatedTime(), 'custom', 'Y-m-d H:i:s'),
        'author' => $node->getOwner()->label(),
        'image' => $this->getImage($node),
        'tags' => $this->getTags($node),
      ];
    }

    $migrated = $this->migrateToStoryblok($toBeExported);

    $this->logger()->success(sprintf('Exported %d articles to Storyblok.', $migrated));
    $this->logger()->success(dt('Content succesfully exported 🎉'));
  }

  /**
   *
   */
  private function getImage(NodeInterface $node): ?array {
    if ($node->get('field_image')->isEmpty()) {
      return NULL;
    }

    $file = $node->get('field_image')->entity;
    if (!$file) {
      return NULL;
    }

    return [
      'uri' => $file->getFileUri(),
      'filename' => $file->getFilename(),
    ];
  }

  /**
   *
   */
  private function getTags(NodeInterface $node): array {
    $tags = [];
    foreach ($node->get('field_tags') as $tag) {
      if ($tag->entity) {
        $tags[] = $tag->entity->label();
      }
    }
    return $tags;
  }

  /**
   *
   */
  private function migrateToStoryblok(array $content): int {
    $migratedCount = 0;
    $managementApi = $this->storyblokClient->managementApi();
    $assetApi = $this->storyblokClient->assetApi($this->spaceId);

    foreach ($content as $item) {
      // Upload image if exists.
      $imageUrl = NULL;
      if (!empty($item['image'])) {
        $this->output()->writeln("Uploading image: " . $item['image']['filename']);
        $imageUrl = $this->uploadImageToStoryblok($assetApi, $item['image']);
      }

      try {
        $storyData = [
          'story' => [
            'name' => $item['title'],
            'created_at' => $item['created_date'],
            'slug' => $this->generateSlug($item['title']),
            'content' => [
              'component' => 'article',
              'title' => $item['title'],
              'body' => $item['body'],
              'image' => [
                "id" => NULL,
                "alt" => NULL,
                "name" => "",
                "focus" => "",
                "title" => NULL,
                "source" => NULL,
                "filename" => $imageUrl,
                "copyright" => NULL,
                "fieldtype" => "asset",
                "meta_data" => [],
                "is_external_url" => FALSE,
              ],
              'tags' => $item['tags'],
            ],
            "is_folder" => FALSE,
            "parent_id" => 0,
            "disable_fe_editor" => FALSE,
            "path" => NULL,
            "is_startpage" => FALSE,
            "publish" => FALSE,
          ],
        ];

        $response = $managementApi->post(
          "spaces/{$this->spaceId}/stories",
          $storyData
        );

        if ($response->isOk()) {
          $migratedCount++;
          $this->logger()->success("Successfully migrated: " . $item['title']);
        }
        else {
          $this->logger()->error("Failed to migrate: " . $item['title'] . ". Error: " . $response->getErrorMessage());
        }
      }
      catch (\Exception $e) {
        $this->logger()->error("Error migrating: " . $item['title'] . ". Error: " . $e->getMessage());
      }
    }

    return $migratedCount;
  }

  /**
   *
   */
  private function uploadImageToStoryblok(AssetApi $assetApi, array $image): ?string {
    if (!isset($image['uri']) || !isset($image['filename'])) {
      $this->logger()->error('Invalid image array provided');
      return NULL;
    }

    try {
      $filePath = $this->fileSystem->realpath($image['uri']);

      // Use AssetApi's upload method which handles all three steps internally.
      $response = $assetApi->upload($filePath);

      if ($response->isOk()) {
        $this->logger()->success("Successfully uploaded image: " . $image['filename']);
        // Get the URL of the uploaded asset.
        return $response->data()->get('filename');
      }
      else {
        throw new \RuntimeException("Failed to upload image: " . $response->getErrorMessage());
      }
    }
    catch (\RuntimeException $e) {
      $this->logger()->error("Runtime error uploading image: " . $e->getMessage());
      return NULL;
    }
    catch (\Exception $e) {
      $this->logger()->error("Unexpected error uploading image: " . $e->getMessage());
      return NULL;
    }
  }

  /**
   *
   */
  private function generateSlug(string $title): string {
    return strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title));
  }

}

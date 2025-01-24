<?php
namespace Drupal\storyblok_exporter\Drush\Commands;

use Storyblok\Mapi\Data\AssetData;
use Storyblok\Mapi\Data\StoryData;
use Storyblok\Mapi\Data\StoryblokData;
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
use Storyblok\Mapi\Endpoints\TagApi;
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
   * @param mixed $options
   */
  #[CLI\Command(name: 'storyblok_exporter:export', aliases: ['sbe'])]
  #[CLI\Option(name: 'limit', description: 'LIMIT the number of nodes to export')]
  #[CLI\Usage(name: 'storyblok_exporter:export --limit=10', description: 'Export and migrate up to 10 articles to Storyblok')]
  public function commandName($options = ['limit' => NULL]): void {
    $query = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('type', self::COMPONENT_TYPE);

    if ($options['limit']) {
      $query->range(0, $options['limit']);
    }

    $nids = $query->execute();

    /** @var \Drupal\node\NodeInterface[] $nodes */
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
    $this->logger()->success(sprintf('Content succesfully exported 🎉'));
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
   * @param array<int,mixed> $content
   */
  private function migrateToStoryblok(array $content): int {
    $migratedCount = 0;
    $storyApi = $this->storyblokClient->storyApi($this->spaceId);
    $assetApi = $this->storyblokClient->assetApi($this->spaceId);
    $tagApi = $this->storyblokClient->tagApi($this->spaceId);

    foreach ($content as $item) {
      // Upload image to Storyblok.
      $imageUrl = NULL;
      if (!empty($item['image'])) {
        $this->output()->writeln("Uploading image: " . $item['image']['filename']);
        $image = $this->uploadImageToStoryblok($assetApi, $item['image']);
      }

      // Create tags in Storyblok.
      if (!empty($item['tags'])) {
        $this->output()->writeln("Creating tags: " . implode(', ', $item['tags']));
        $this->createTagsInStoryblok($tagApi, $item['tags']);
      }

      try {
        $storyContent = new StoryblokData();
        $storyContent->set('component', self::COMPONENT_TYPE);
        $storyContent->set('title', $item['title']);
        $storyContent->set('body', $item['body']);
        $storyContent->set('image.id', $image->id());
        $storyContent->set('image.fieldtype', 'asset');
        $storyContent->set('image.filename', $image->filename());

        $story = new StoryData();
        $story->setName($item['title']);
        $story->setSlug($this->generateSlug($item['title']));
        $story->setContentType($this::COMPONENT_TYPE);
        $story->setCreatedAt($item['created_date']);
        $story->setContent($storyContent->toArray());

        $response = $storyApi->create($story);

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
   * @param array<int,mixed> $image
   */
  private function uploadImageToStoryblok(AssetApi $assetApi, array $image): ?AssetData {
    if (!isset($image['uri']) || !isset($image['filename'])) {
      $this->logger()->error('Invalid image array provided');
      return NULL;
    }

    try {
      $filePath = $this->fileSystem->realpath($image['uri']);

      $response = $assetApi->upload($filePath);

      if ($response->isOk()) {
        $this->logger()->success("Successfully uploaded image: " . $image['filename']);
        // Get the URL of the uploaded asset.
        return $response->data();
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
   * @param array<int,mixed> $tags
   */
  private function createTagsInStoryblok(TagApi $tagApi, array $tags): void {
    foreach ($tags as $tag) {
      $response = $tagApi->create($tag);

      if ($response->isOk()) {
        $this->logger()->success("Successfully created tag: " . $tag);
      }
      else {
        $this->logger()->error("Failed to create tag: " . $tag . ". Error: " . $response->getErrorMessage());
      }
    }
  }

  /**
   *
   */
  private function generateSlug(string $title): string {
    return strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title));
  }

}

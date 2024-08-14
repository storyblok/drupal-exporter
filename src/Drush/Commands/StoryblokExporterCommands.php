<?php

namespace Drupal\storyblok_exporter\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Utility\Token;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drupal\node\NodeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * A Drush commandfile.
 */
final class StoryblokExporterCommands extends DrushCommands {

  use AutowireTrait;

  private const STORYBLOK_API_URL = 'https://mapi.storyblok.com/v1/spaces/';

  /**
   * Constructs a StoryblokExporterCommands object.
   */
  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private DateFormatterInterface $dateFormatter,
    private FileUrlGeneratorInterface $fileUrlGenerator,
    private FileSystemInterface $fileSystem,
    private ClientInterface $httpClient,
  ) {
    parent::__construct();
  }

  /**
   * Command description here.
   */
  #[CLI\Command(name: 'storyblok_exporter:export', aliases: ['sbe'])]
  #[CLI\Option(name: 'limit', description: 'LIMIT the number of nodes to export')]
  #[CLI\Usage(name: 'storyblok_exporter:export --limit=10', description: 'Export and migrate up to 10 articles to Storyblok')]
  public function commandName($options = ['limit' => null]) {
    $query = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('type', 'article');

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

    //$this->output()->writeln(print_r($toBeExported, TRUE));

    $migrated = $this->migrateToStoryblok($toBeExported);

    $this->logger()->success(sprintf('Exported %d articles to Storyblok.', $migrated));
    $this->logger()->success(dt('Content succesfully exported 🎉'));
  }

  private function getImage(NodeInterface $node): ?array {
    if ($node->get('field_image')->isEmpty()) {
      return null;
    }

    $file = $node->get('field_image')->entity;
    if (!$file) {
      return null;
    }

    return [
      'uri' => $file->getFileUri(),
      'filename' => $file->getFilename(),
    ];
  }

  private function getTags(NodeInterface $node): array {
    $tags = [];
    foreach ($node->get('field_tags') as $tag) {
      if ($tag->entity) {
        $tags[] = $tag->entity->label();
      }
    }
    return $tags;
  }

  private function migrateToStoryblok(array $content): int {
    $migratedCount = 0;

    foreach ($content as $item) {
      if (!empty($item['tags'])) {
        foreach($item['tags'] as $tag) {
          try {
            $response = $this->httpClient->request('POST', self::STORYBLOK_API_URL . Settings::get('STORYBLOK_SPACE_ID', null) . '/datasource_entries', [
              'headers' => [
                'Authorization' => Settings::get('STORYBLOK_OAUTH_TOKEN', null),
                'Content-Type' => 'application/json',
              ],
              'json' => [
                "datasource_entry" =>  [
                  "name" => $tag,
                  "value" => $tag,
                  "datasource_id" => Settings::get('STORYBLOK_DATASOURCE_ID', null),
                ],
              ],
            ]);

            if ($response->getStatusCode() === 201) {
              $this->output()->writeln("Successfully created datasource entry: " . $tag);
            } else {
              $this->output()->writeln("Failed create datasource entry: " . $tag . ". Status code: " . $response->getStatusCode());
            }
          } catch (GuzzleException $e) {}
        }
      }

      // Upload image if exists
      $imageUrl = null;
      if (!empty($item['image'])) {
        $this->output()->writeln("Uploading image: " . $item['image']['filename']);
        $imageUrl = $this->uploadImageToStoryblok($item['image']);
      }

      try {
        $response = $this->httpClient->request('POST', self::STORYBLOK_API_URL . Settings::get('STORYBLOK_SPACE_ID', null) . '/stories', [
          'headers' => [
            'Authorization' => Settings::get('STORYBLOK_OAUTH_TOKEN', null),
            'Content-Type' => 'application/json',
          ],
          'json' => [
            'story' => [
              'name' => $item['title'],
              'created_at' => $item['created_date'],
              'slug' => $this->generateSlug($item['title']),
              'content' => [
                'component' => 'article',
                'title' => $item['title'],
                'body' => $item['body'],
                'image' => [
                  "id" => null,
                  "alt" => null,
                  "name" => "",
                  "focus" => "",
                  "title" => null,
                  "source" => null,
                  "filename" => $imageUrl,
                  "copyright" => null,
                  "fieldtype" => "asset",
                  "meta_data" => [],
                  "is_external_url" => false,
                ],
                'tags' => $item['tags'],
              ],
              "is_folder" => false,
              "parent_id" => 0,
              "disable_fe_editor" => false,
              "path" => null,
              "is_startpage" => false,
              "publish" => false,
            ],
          ],
        ]);

        if ($response->getStatusCode() === 201) {
          $migratedCount++;
          $this->output()->writeln("Successfully migrated: " . $item['title']);
        } else {
          $this->output()->writeln("Failed to migrate: " . $item['title'] . ". Status code: " . $response->getStatusCode());
        }
      } catch (GuzzleException $e) {
        $this->output()->writeln("Error migrating: " . $item['title'] . ". Error: " . $e->getMessage());
      }
    }

    return $migratedCount;
  }

  private function uploadImageToStoryblok(array $image): ?string {
    try {
      $filePath = $this->fileSystem->realpath($image['uri']);

      $response = $this->httpClient->request('POST', self::STORYBLOK_API_URL . Settings::get('STORYBLOK_SPACE_ID', null) . '/assets', [
        'headers' => [
          'Authorization' => Settings::get('STORYBLOK_OAUTH_TOKEN', null),
          'Content-Type' => 'application/json',
        ],
        'json' => [
          "filename" => $image['filename'],
          "validate_upload" =>  1
        ],
      ]);

      if ($response->getStatusCode() !== 200) {
        throw new \RuntimeException("Failed to initiate upload: " . $response->getBody());
      }

      $signedResponse = json_decode($response->getBody()->getContents(), true);

      $multipart = [];
      foreach ($signedResponse['fields'] as $key => $value) {
        $multipart[] = [
          'name' => $key,
          'contents' => $value,
        ];
      }
      $multipart[] = [
        'name' => 'file',
        'contents' => fopen($filePath, 'r'),
        'filename' => $image['filename'],
      ];

      $s3Response = $this->httpClient->request('POST', $signedResponse['post_url'], [
        'multipart' => $multipart,
      ]);

      if ($s3Response->getStatusCode() !== 204) {
        throw new \RuntimeException("Failed to upload to S3: " . $s3Response->getBody());
      }

      $finalizeResponse = $this->httpClient->request('GET', self::STORYBLOK_API_URL . Settings::get('STORYBLOK_SPACE_ID', null) . '/assets/' . $signedResponse['id'] . '/finish_upload', [
        'headers' => [
          'Authorization' => Settings::get('STORYBLOK_OAUTH_TOKEN', null),
        ],
      ]);

      if ($finalizeResponse->getStatusCode() !== 200) {
        throw new \RuntimeException("Failed to finalize upload: " . $finalizeResponse->getBody());
      }

      $this->output()->writeln("Successfully uploaded image: " . $image['filename']);
      return $signedResponse['pretty_url'];
    } catch (GuzzleException $e) {
      $this->output()->writeln("Error uploading image: " . $image['filename'] . ". Error: " . $e->getMessage());
    }

    return null;
  }

  private function generateSlug(string $title): string {
    return strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title));
  }
}

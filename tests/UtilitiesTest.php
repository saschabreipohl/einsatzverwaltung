<?php
namespace abrain\Einsatzverwaltung;

use abrain\Einsatzverwaltung\Model\IncidentReport;
use WP_UnitTestCase;

/**
 * Tests für diverse Hilfsfunktionen
 *
 * @author Andreas Brain
 * @coversDefaultClass abrain\Einsatzverwaltung\Utilities
 */
class UtilitiesTest extends WP_UnitTestCase
{
    /**
     * @var Utilities
     */
    private $utilities;

    /**
     * @inheritDoc
     */
    public function setUp()
    {
        parent::setUp();
        $this->utilities = new Utilities(null);
    }

    /**
     * @covers ::addPostToCategory
     */
    public function testAddPostToCategory()
    {
        // Einsatzbericht anlegen und mehreren Kategorien zuweisen
        $postId = $this->factory->post->create(array('post_type' => 'einsatz'));
        $categoryIds = $this->factory->category->create_many(4);
        $initialCategories = array_slice($categoryIds, 0, 2);
        wp_set_post_categories($postId, $initialCategories);
        $this->assertEquals($initialCategories, wp_get_post_categories($postId));

        // Kategorie hinzufügen und prüfen, ob die Aktion erfolgreich war
        $this->utilities->addPostToCategory($postId, $categoryIds[2]);
        $this->assertEquals(array_slice($categoryIds, 0, 3), wp_get_post_categories($postId));

        // Kategorie hinzufügen und prüfen, ob die Aktion erfolgreich war
        $this->utilities->addPostToCategory($postId, $categoryIds[3]);
        $this->assertEquals($categoryIds, wp_get_post_categories($postId));
    }

    /**
     * @covers ::postsToIncidentReports
     */
    public function testPostsToIncidentReports()
    {
        $posts = $this->factory->post->create_many(5, array('post_type' => 'einsatz'));

        $reports = $this->utilities->postsToIncidentReports($posts);
        $this->assertCount(5, $reports);

        foreach ($reports as $key => $report) {
            /** @var IncidentReport $report */
            $this->assertEquals($posts[$key], $report->getPostId());
        }
    }

    /**
     * @covers ::removePostFromCategory
     */
    public function testRemovePostFromCategory()
    {
        // Einsatzbericht anlegen und allen Kategorien zuweisen
        $postId = $this->factory->post->create(array('post_type' => 'einsatz'));
        $categoryIds = $this->factory->category->create_many(4);
        wp_set_post_categories($postId, $categoryIds);
        $this->assertEquals($categoryIds, wp_get_post_categories($postId));

        // Kategorie entfernen und prüfen, ob die Aktion erfolgreich war
        $this->utilities->removePostFromCategory($postId, $categoryIds[0]);
        $this->assertEquals(array_slice($categoryIds, 1), wp_get_post_categories($postId));

        // Kategorie entfernen und prüfen, ob die Aktion erfolgreich war
        $this->utilities->removePostFromCategory($postId, $categoryIds[3]);
        $this->assertEquals(array_slice($categoryIds, 1, 2), wp_get_post_categories($postId));
    }
}

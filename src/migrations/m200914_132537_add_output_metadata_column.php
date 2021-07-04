<?php

namespace yoannisj\coconut\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\Db as DbHelper;

use yoannisj\coconut\Coconut;

/**
 * m200914_132537_add_output_metadata_column migration.
 */

class m200914_132537_add_output_metadata_column extends Migration
{
    /**
     * @inheritdoc
     */

    public function safeUp()
    {
        if ($this->db->tableExists(Coconut::TABLE_OUTPUTS))
        {
            $this->addColumn(Coconut::TABLE_OUTPUTS, 'metadata', $this->longText()->null());

            return true;
        }

        return false;
    }

    /**
     * @inheritdoc
     */

    public function safeDown()
    {
        if ($this->db->tableExists(Coconut::TABLE_OUTPUTS)) {
            $this->dropColumn(Coconut::TABLE_OUTPUTS, 'metadata');
        }

        return true;
    }
}

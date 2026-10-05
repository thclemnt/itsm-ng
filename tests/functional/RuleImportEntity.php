<?php

namespace tests\units;

use DbTestCase;

class RuleImportEntity extends DbTestCase
{
    public function testExecuteActionsResolvesEntityTag(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $child = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $sibling = (int)getItemByTypeName('Entity', '_test_child_2', true);
        $value = "_import_O'Reilly\\branch";
        $connection->update('glpi_entities', ['tag' => $value], ['id' => $child]);
        $rule = new \RuleImportEntity();
        $action = new \RuleAction();
        $action->fields = [
            'action_type' => 'regex_result',
            'field' => '_affect_entity_by_tag',
            'value' => $value,
        ];
        $rule->actions = [$action];

        $this->array($rule->executeActions([], []))->isIdenticalTo(['entities_id' => $child]);
        $connection->update('glpi_entities', ['tag' => $value], ['id' => $sibling]);
        // Preserve the rule's existing unresolved/ambiguous sentinel.
        $this->array($rule->executeActions([], []))->isIdenticalTo(['entities_id' => -1]);
        $action->fields['value'] = $value . '_root';
        $this->array($rule->executeActions([], []))->isIdenticalTo(['entities_id' => -1]);
        $connection->update('glpi_entities', ['tag' => $action->fields['value']], ['id' => 0]);
        $this->array($rule->executeActions([], []))->isIdenticalTo(['entities_id' => 0]);
    }

    public function testExecuteActionsAssignsEntity()
    {
        $entities_id = (int)getItemByTypeName('Entity', '_test_child_1', true);
        $rule = new \RuleImportEntity();
        $action = new \RuleAction();
        $action->fields = [
           'action_type' => 'assign',
           'field'       => 'entities_id',
           'value'       => $entities_id,
        ];
        $rule->actions = [$action];

        $result = $rule->executeActions([], []);
        $this->array($result)->isIdenticalTo([
           'entities_id' => $entities_id,
        ]);
    }
}

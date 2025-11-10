# Gravity Forms - Conditional Choices

**Contributors:** AI Assistant  
**Version:** 2.0.0  
**Requires at least:** 5.5  
**Tested up to:** 6.4
**Requires PHP:** 7.4
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  

A powerful enhancement for Gravity Forms that allows you to dynamically filter the choices of a field based on the values of other fields in the form.

## Description

This plugin allows you to set up conditional logic for fields with choices (such as Dropdowns, Radio Buttons, and Checkboxes). When a user interacts with a "source" field, the available choices in a "target" field are updated in real-time based on the rules you define.

This is perfect for creating dependent dropdowns (e.g., Country -> State/Province), filtering product options, or building dynamic registration forms.

## Features

*   **Multiple Configurations:** Create conditional logic for multiple different fields within the same form.
*   **Multiple Condition Groups:** For each target field, create multiple groups of conditions. The first group whose conditions are met will be applied.
*   **Multiple Rules:** Each group can have multiple rules, with `AND` (`All`) or `OR` (`Any`) logic.
*   **Rich Operators:** Use a variety of operators: `is`, `is not`, `greater than`, `less than`, `contains`, `starts with`, `ends with`.
*   **Intuitive UI:** A clean, modern, drag-and-drop interface for managing rules and choices.
*   **Supported Fields:** Works with Dropdown, Radio Button, and Checkbox fields as both sources and targets.
*   **Efficient:** The frontend script is lightweight and is only loaded on pages where a form with active conditional rules is present.

## Installation

1.  Download the plugin `.zip` file.
2.  In your WordPress admin dashboard, go to **Plugins > Add New**.
3.  Click **Upload Plugin** and select the `.zip` file you downloaded.
4.  Activate the plugin after installation.

## How to Use

Once installed, you can configure conditional choices directly within your form's settings.

1.  Navigate to the form you wish to edit.
2.  Go to **Form Settings > Conditional Choices**.

### Creating a New Configuration

You will see a list of all existing configurations for the form.

1.  Click the **"Add New"** button to get started.

2.  **Select the Target Field:** From the "Target Field" dropdown, choose the field whose choices you want to change dynamically. *Note: A field can only be a target once. Fields that already have a configuration will not appear in this list.*

3.  **Create Condition Groups:** A configuration is made up of one or more "Condition Groups". The plugin will check the groups in order from top to bottom and apply the **first group that matches**.

4.  **Define Rules:**
    *   Inside a group, click **"Add Rule"** to create a condition.
    *   **Select a Source Field:** The field that will trigger the logic.
    *   **Select an Operator:** How to compare the source field's value (e.g., `is`, `contains`).
    *   **Enter a Value:** The value to check for.
    *   Use the **All / Any** dropdown at the top of the group to determine if all rules must match (`AND` logic) or if only one needs to match (`OR` logic).

5.  **Assign Choices:**
    *   On the right side of the screen, the **"Available Choices"** box shows all possible choices for your selected target field.
    *   **Drag and drop** the choices you want to show from the "Available Choices" box into the **"Choices to Show"** area within your condition group.

6.  **Save:** Click the **"Save Configuration"** button.

### Example: Country and State/Province

Let's say you have two fields: "Country" (Dropdown) and "State" (Dropdown). You want the "State" dropdown to show US states if the user selects "USA" and Canadian provinces if they select "Canada".

1.  **Create a new configuration** and select "State" as the target field.

2.  **Create the first Condition Group** for the USA.
    *   **Label:** "USA States"
    *   **Rule:** If `Country` `is` `USA`.
    *   **Choices to Show:** Drag "California", "New York", "Texas", etc., into this group's choice area.

3.  **Create a second Condition Group** for Canada.
    *   Click **"Add Condition Group"**.
    *   **Label:** "Canadian Provinces"
    *   **Rule:** If `Country` `is` `Canada`.
    *   **Choices to Show:** Drag "Ontario", "Quebec", "British Columbia", etc., into this group's choice area.

4.  **Save the configuration.**

Now, on the frontend, when a user selects "USA" from the "Country" dropdown, the "State" field will automatically be populated with only the US states you assigned. If they switch to "Canada", the list will update to show the provinces. If they select a country with no rules, the "State" field will revert to its original, complete list of choices.

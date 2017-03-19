jQuery(document).ready( function($) {
//TODO encapsulate, privatize and perhaps add classes where appropriate
//TODO validation, sanitize, edge case analysis, fail safes, error handling, optimization, JQuery removal or Updating
//TODO all validation MUST occur 1+ times on server side, JS validation is for the user only
//TODO consider how to handle if any step fails on the way since some apis are outside of our control
//TODO limit the number of emails that can be sent by a single user, look at cookies, session ID since name could be the same
//though postal code shouldn't be?

jQuery('input[type=checkbox]').each(toggleListCheckbox).change(toggleListCheckbox);
/*--------------------------------Utility Functions-----------------------------------------*/


/*--------------------------------Data Manipulation Functions-----------------------------------------*/

// from stackovaflo + edits made to replace whitespaces in between
function postalFilter (postalCode) {
    var us = new RegExp("^\\d{5}(-{0,1}\\d{4})?$");
    var ca = new RegExp(/([ABCEGHJKLMNPRSTVXY]\d)([ABCEGHJKLMNPRSTVWXYZ]\d){2}/i);

    if (! postalCode) {
        return null;
    }

    postalCode = postalCode.toString().trim();
    postalCode = postalCode.replace(/\s/g, '');

    if (us.test(postalCode.toString())) {
        return postalCode;
    }

    if (ca.test(postalCode.toString().replace(/\W+/g, ''))) {
        return postalCode;
    }
    return null;
}

function toggleListCheckbox() {
    var checkboxId = jQuery(this).attr('id');
    var checkMessage = jQuery(this).attr('data-msg');

    if(jQuery(this).is(':checked')) {
        jQuery('.petition-message-display').append('<p class="' + checkboxId  + '">' + checkMessage  + '</p>');
    } else {
        jQuery('.petition-message-display .' + checkboxId).remove();
    }
}

/*--------------------------------DOM Functions-----------------------------------------*/
/* handle the form logic in JS */
function handleForm(e) {
  var postalCode = e.srcElement['postal_code'].value;
  //TODO validate the username
  var userName = e.srcElement['user_name'].value;
  // make an array of the checked checkboxes
  var userEmail = e.srcElement['user_email'].value;
  var userMessages = jQuery(".rep-petition-form input:checkbox:checked").map(function(){
    return jQuery(this).val();
  }).get();
  // console.log(userMessages);

  if (e.preventDefault) {
    e.preventDefault();
  }


  // TODO pass everything to a validate function
  /* validate, trim and remove spaces*/
  postalCode = postalFilter(postalCode);
  if (postalCode !== null && postalCode !== undefined) {
    // TODO remove hardcoding template value
    pxe_plugin_init( postalCode, userName, userEmail, userMessages );
  } else {
    if ( jQuery("#petition-error-div").children().length === 0 ) {
      jQuery("#petition-error-div").append( "<strong>Invalid postal code</strong>" );
    }
  }
  return false;
}

/*--------------------------------Program Functions-----------------------------------------*/

function pxe_plugin_init ( postalCode, name, email, messages) {
  jQuery(".load-container").append( "<div class='load-spinner'></div>" );
  jQuery("input").prop('disabled', true);
   jQuery.ajax({
    type:'POST',
    data:{
      action:'pxe_main_process_async',
      postalCode: postalCode,
      name: name,
      email: email,
      messages: messages
    },
    url: "http://yegsoccer.web.dmitcapstone.ca/wordpress/wp-admin/admin-ajax.php",
    success: function(value) {
      // jQuery(this).html(value);
      console.log(JSON.parse(value) );
    jQuery(".load-spinner").remove();
      // console.log(value);
      showResults( JSON.parse( value ) );
    },
    error: function(error) {
      console.log(error);
    }
  });
}

// display the representatives and success message to user
function showResults (repsData) {
  var display = jQuery("#rep-info-display");

  // hide the form
  jQuery(".rep-petition-form").hide( "slow" );
  // show reps display
  jQuery("#rep-info-display").css( "display", "block" );
  display.append( "<p>Thank you for your support, the email has been sent to your local representatives.  Check your email for contact information for you to followup.</p>" );
  //handle each rep
  for( var j=0; j<repsData.length; j++ ){
      display.append( "<div class='representative-info rep-" + ( j +1 ) + "'>" );
      var innerDisplay = jQuery( ".rep-" + ( j+1 ) );
      innerDisplay.append( "<img src='" + repsData[j].photo_url + "' class='rep-photo' alt='" + repsData[j].name + "'>" );
      innerDisplay.append( "<p class='rep-name'>" + repsData[j].name + "</p>");
      innerDisplay.append( "<p class='rep-position'>" + repsData[j].elected_office + "</p>");
      innerDisplay.append( "<p class='rep-email'>" + repsData[j].email + "</p>");
      display.append( "</div>");
  }

}

// ----------------------------------------------- Set the event handler



	// attach event listeners to the form
	// if (postalForm.attachEvent) {
	//   postalForm.attachEvent("submit", handleForm);
	// } else {
	//   postalForm.addEventListener("submit", handleForm);
	// }
  // TODO, bind a function like handle form, this is getting a bit big
  // TODO update jquery version and syntax
  jQuery(".rep-petition-form").bind("submit", handleForm);

});


var Permission = React.createClass({
    displayName: 'Permission',

    propTypes: {
        permission: React.PropTypes.object,
        type: React.PropTypes.string
    },

    getDefaultProps: function () {
        return {
            modeEnable: false
        };
    },

    getInitialState: function () {
        return this.init(this.props);
    },

    init: function (props){
        var permission = props.permission;

        var mode;
        var rating;

        var includeGroups=[];
        var includeMembers=[];
        var excludeMembers=[];

        if(permission){
            mode = permission.mode;
            rating = permission.rating;
            includeGroups = permission.group;
            includeMembers = permission.user;
            excludeMembers = permission.except;
        }
        return {
            permission : permission,
            modeEnable : this.props.modeEnable,
            formData : {
                mode: mode,
                rating: rating
            },
            includeGroups: includeGroups,
            includeMembers: includeMembers,
            excludeMembers: excludeMembers
        };
    },

    componentDidMount: function (){
    },

    componentDidUpdate: function (prevProps){
        var permission = this.props.permission;
        if ( permission !== prevProps.permission) {
            this.setState(this.init(this.props));
        }

    },

    inputChange: function (key, event){
        var value = event.target.value;
        var formData = this.state.formData;

        formData[key] = value;

        this.setState({
            formData: formData
        });
    },

    handleIncludeGroupDelete: function (i) {
        var tags = this.state.includeGroups;
        tags.splice(i, 1);
        this.setState({includeGroups: tags});
    },
    handleIncludeMemberDelete: function (i) {
        var tags = this.state.includeMembers;
        tags.splice(i, 1);
        this.setState({includeMembers: tags});
    },
    handleExcludeMemberDelete: function (i) {
        var tags = this.state.excludeMembers;
        tags.splice(i, 1);
        this.setState({excludeMembers: tags});
    },
    handleIncludeAddition: function (tag) {
        var includeGroups = this.state.includeGroups;
        var includeMembers = this.state.includeMembers;

        var finded;

        if(tag.hasOwnProperty('displayName')){
            finded = _.find(includeMembers, {id:tag.id});

            if(!finded){
                includeMembers.push(tag);
                this.setState({includeMembers: includeMembers});
            }
        }else{
            finded = _.find(includeGroups, {id:tag.id});

            if(!finded){
                includeGroups.push(tag);
                this.setState({includeGroups: includeGroups});
            }
        }
    },

    handleExcludeAddition: function (tag) {
        var tags = this.state.excludeMembers;
        var finded = _.find(tags, {id:tag.id});

        if(!finded){
            tags.push(tag);
            this.setState({excludeMembers: tags});
        }
    },

    render: function (){
        var self = this;

        var modeEnable = this.props.modeEnable;
        var modeTitle = this.props.type + 'Mode';
        var ratingTitle = this.props.type + 'Rating';
        var includeGroupTitle = this.props.type + 'Group';
        var includeMemberTitle = this.props.type + 'User';
        var excludeMemberTitle = this.props.type + 'Except';
        var includeVGroupTitle = this.props.type + 'VGroup[]';

        var modeValue = this.state.formData.mode;
        var ratingValue = this.state.formData.rating;

        var controlDisabled = false;

        if(modeValue === 'manual' || modeValue === 'inherit') {
            modeEnable = true;
            if(modeValue !== 'manual') {
                controlDisabled = true;
            }
        }

        var modeOptions = [
            {value: 'inherit', name: '상위 설정에 따름'}
            , {value: 'manual', name: '직접 설정'}
        ];

        var ratingOption = [
            {value: 'super', name: 'Super'}
            , {value: 'manager', name: 'Manager'}
            , {value: 'member', name: 'Member'}
            , {value: 'guest', name: 'Guest'}
        ];

        var ModeSelectUI =
            modeOptions.map(function (data) {
                return React.createElement("option", {value: data.value, key: data.value}, data.name);
            });

        var RatingUI =
            ratingOption.map(function (data) {
                if (data.value == ratingValue)
                    return React.createElement("label", null, React.createElement("input", {type: "radio", disabled: controlDisabled, name: ratingTitle, key: data.value, value: data.value, checked: true, 
                                  onChange: self.inputChange.bind(null, 'rating')}), " ", data.name, "  ");
                else
                    return React.createElement("label", null, React.createElement("input", {type: "radio", disabled: controlDisabled, name: ratingTitle, key: data.value, value: data.value, 
                                  onChange: self.inputChange.bind(null, 'rating')}), " ", data.name, "  ");
            });

        var VGroupUI = this.props.vgroupAll.length < 1 ? null : this.props.vgroupAll.map(function (data) {
            var inputProps = {
                type: 'checkbox',
                disabled: controlDisabled,
                name: includeVGroupTitle,
                value: data.id,
                key: data.id
            };
            var inArray = function (val, arr) {
                for (var i = 0; i < arr.length; i++) {
                    if (arr[i] == val) {
                        return i;
                    }
                }

                return -1;
            };
            if (inArray(data.id, this.props.permission.vgroup) != -1) {
                inputProps['defaultChecked'] = true;
            }
            return (
                React.createElement("label", null, 
                    React.createElement("input", React.__spread({},  inputProps)), " ", data.title, "  "
                )
            );
        }.bind(this));

        var includeGroups = this.state.includeGroups.map(function (group) {
           return group.id;
        });

        var includeMembers = this.state.includeMembers.map(function (member) {
            return member.id;
        });

        var excludeMembers = this.state.excludeMembers.map(function (member) {
            return member.id;
        });

        var permissionTitle = this.props.type.replace(/\w+/g,
            function(w){return w[0].toUpperCase() + w.slice(1).toLowerCase();});

        var modeUI;

        if(modeEnable)
            modeUI = React.createElement("p", null, 
                React.createElement("label", null, 
                    "Mode  ", 
                    React.createElement("i", {className: "fa fa-info-circle", "data-toggle": "popover", "data-content": "권한의 모드를 설정합니다.", 
                       "data-original-title": ""})
                ), React.createElement("br", null), 
                React.createElement("select", {name: modeTitle, value: modeValue, onChange: this.inputChange.bind(null, 'mode')}, 
                    ModeSelectUI
                )
            );

        return (
            React.createElement("div", null, 
                React.createElement("p", null, 
                    React.createElement("h4", null, permissionTitle, " Permission")
                ), 
                modeUI, 
                React.createElement("p", null, 
                    React.createElement("label", null, 
                        "Rating  ", 
                        React.createElement("i", {className: "fa fa-info-circle", "data-toggle": "popover", "data-content": "권한의 등급을 설정합니다.", 
                           "data-original-title": ""})
                    ), React.createElement("br", null), 
                    RatingUI
                ), 
                React.createElement("p", null, 
                    React.createElement("label", null, 
                        "Include Group and User ", 
                        React.createElement("i", {className: "fa fa-info-circle", "data-toggle": "popover", "data-content": "포함하고자 하는 대상을 지정합니다.", 
                           "data-original-title": ""})
                    ), React.createElement("br", null), 
                    React.createElement(PermissionInclude, {
                        selectedGroup: this.state.includeGroups, 
                        selectedMember: this.state.includeMembers, 
                        searchMemberUrl: this.props.memberSearchUrl, 
                        searchGroupUrl: this.props.groupSearchUrl, 
                        disabled: controlDisabled, 
                        handleGroupDelete: this.handleIncludeGroupDelete, 
                        handleMemberDelete: this.handleIncludeMemberDelete, 
                        handleAddition: this.handleIncludeAddition}
                        ), 
                    React.createElement("input", {type: "hidden", name: includeGroupTitle, className: "form-control", value: includeGroups}), 
                    React.createElement("input", {type: "hidden", name: includeMemberTitle, className: "form-control", value: includeMembers})

                ), 
                function () {
                    if (VGroupUI) {
                        return (
                            React.createElement("p", null, 
                                React.createElement("label", null, 
                                    "Include Virtual Group ", 
                                    React.createElement("i", {className: "fa fa-info-circle", "data-toggle": "popover", "data-content": "포함하고자 하는 대상을 지정합니다.", 
                                       "data-original-title": ""})
                                ), React.createElement("br", null), 
                                VGroupUI
                            )
                        );
                    }
                }.call(this), 
                React.createElement("p", null, 
                    React.createElement("label", null, 
                        "Exclude User  ", 
                        React.createElement("i", {className: "fa fa-info-circle", "data-toggle": "popover", "data-content": "제외하고자 하는 대상을 지정합니다.", 
                           "data-original-title": ""})
                    ), React.createElement("br", null), 
                    React.createElement(PermissionExclude, {
                        selectedMember: this.state.excludeMembers, 
                        searchMemberUrl: this.props.memberSearchUrl, 
                        disabled: controlDisabled, 
                        handleDelete: this.handleExcludeMemberDelete, 
                        handleAddition: this.handleExcludeAddition}
                        ), 
                    React.createElement("input", {type: "hidden", name: excludeMemberTitle, className: "form-control", value: excludeMembers})
                )
            )
        );
    }
});



//# sourceMappingURL=Permission.js.map